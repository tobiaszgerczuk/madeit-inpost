/**
 * Wybór paczkomatu InPost w checkoucie klasycznym i blokowym.
 * Mapa to oficjalny widżet InPost Geowidget; bez tokenu pokazujemy pole na kod punktu.
 */
(function () {
    'use strict';

    var cfg = window.madeitInpost;
    if (!cfg) {
        return;
    }

    var state = { point: '', description: '' };
    var validName = new RegExp(cfg.validName);
    var lastSent = '';
    var widgetLoaded = false;

    function pickers() {
        return document.querySelectorAll('[data-madeit-inpost]');
    }

    function blocksStore() {
        var data = window.wp && window.wp.data;
        return data && data.select('wc/store/cart') ? data : null;
    }

    function inpostSelected() {
        var data = blocksStore();
        if (data && document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout')) {
            var store = data.select('wc/store/cart');
            var rates = typeof store.getShippingRates === 'function' ? store.getShippingRates() : [];
            return rates.some(function (pack) {
                return (pack.shipping_rates || []).some(function (rate) {
                    return rate.selected && rate.method_id === cfg.methodId;
                });
            });
        }
        var checked = document.querySelector('input[name^="shipping_method"]:checked, input[type="hidden"][name^="shipping_method"]');
        return !!checked && checked.value.indexOf(cfg.methodId + ':') === 0;
    }

    function formatSelected() {
        return cfg.content.selected.replace('{point}', state.point).replace('{address}', state.description).replace(/,\s*$/, '');
    }

    function pushToBlocks() {
        var data = blocksStore();
        if (!data) {
            return;
        }
        var payload = JSON.stringify(state);
        if (payload === lastSent) {
            return;
        }
        lastSent = payload;
        var checkout = data.dispatch('wc/store/checkout');
        var setter = checkout.setExtensionData || checkout.__unstableSetExtensionData;
        if (typeof setter === 'function') {
            setter(cfg.extension, { point: state.point, description: state.description });
        }
    }

    var shippingSelector = '.wc-block-components-shipping-rates-control';

    // W checkoucie blokowym picker trafia z PHP pod cały blok. Przenosimy go pod opcje wysyłki, żeby klient widział go zaraz po wyborze InPost.
    function placeBlocksPicker(picker) {
        var control = document.querySelector(shippingSelector);
        if (!control || !control.parentNode || picker.previousElementSibling === control) {
            return;
        }
        control.parentNode.insertBefore(picker, control.nextSibling);
    }

    function render() {
        var visible = inpostSelected();
        pickers().forEach(function (picker) {
            if (picker.classList.contains('madeit-inpost--blocks')) {
                placeBlocksPicker(picker);
            }
            picker.hidden = !visible;
            var selected = picker.querySelector('[data-madeit-inpost-selected]');
            var open = picker.querySelector('[data-madeit-inpost-open]');
            if (selected) {
                var text = state.point ? formatSelected() : '';
                selected.hidden = !state.point;
                if (selected.textContent !== text) {
                    selected.textContent = text;
                }
            }
            if (open) {
                var label = state.point ? cfg.content.button_change : cfg.content.button_choose;
                if (open.textContent !== label) {
                    open.textContent = label;
                }
            }
            var point = picker.querySelector('input[name="madeit_inpost_point"]');
            var description = picker.querySelector('input[name="madeit_inpost_point_desc"]');
            if (point) {
                point.value = state.point;
            }
            if (description) {
                description.value = state.description;
            }
        });
        pushToBlocks();
    }

    function setPoint(name, description) {
        state.point = name;
        state.description = description || '';
        render();
    }

    window.madeitInpostOnPoint = function (point) {
        if (!point || !point.name) {
            return;
        }
        var address = point.address || {};
        var details = point.address_details || {};
        var parts = [address.line1, address.line2].filter(Boolean);
        if (!parts.length) {
            parts = [[details.street, details.building_number].filter(Boolean).join(' '), details.city].filter(Boolean);
        }
        setPoint(String(point.name).toUpperCase(), parts.join(', ') || point.location_description || '');
        closeModal();
    };

    var overlay = null;

    function closeModal() {
        if (overlay) {
            overlay.remove();
            overlay = null;
        }
    }

    function loadWidget(done) {
        if (widgetLoaded || window.customElements.get('inpost-geowidget')) {
            widgetLoaded = true;
            done();
            return;
        }
        var script = document.createElement('script');
        script.src = cfg.widgetScript;
        script.async = true;
        script.onload = function () {
            widgetLoaded = true;
            done();
        };
        document.head.appendChild(script);
    }

    function openModal() {
        if (overlay) {
            return;
        }
        overlay = document.createElement('div');
        overlay.className = 'madeit-inpost-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML = '<div class="madeit-inpost-modal__box"><button type="button" class="madeit-inpost-modal__close" aria-label="Zamknij">×</button><div class="madeit-inpost-modal__map"></div></div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay || event.target.classList.contains('madeit-inpost-modal__close')) {
                closeModal();
            }
        });
        loadWidget(function () {
            var widget = document.createElement('inpost-geowidget');
            widget.setAttribute('token', cfg.token);
            widget.setAttribute('language', cfg.language);
            widget.setAttribute('config', 'parcelCollect');
            widget.setAttribute('onpoint', 'madeitInpostOnPoint');
            var holder = overlay && overlay.querySelector('.madeit-inpost-modal__map');
            if (holder) {
                holder.appendChild(widget);
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-madeit-inpost-open]')) {
            event.preventDefault();
            openModal();
        }
    });

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('[data-madeit-inpost-manual]')) {
            return;
        }
        var value = input.value.trim().toUpperCase();
        input.value = value;
        setPoint(validName.test(value) ? value : '', '');
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('input[name^="shipping_method"]')) {
            render();
        }
    });

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', render);
    }

    var data = blocksStore();
    var blocksRoot = document.querySelector('.wp-block-woocommerce-checkout');
    if (blocksRoot && window.MutationObserver) {
        var watchQueued = false;
        new MutationObserver(function () {
            if (watchQueued) {
                return;
            }
            watchQueued = true;
            window.requestAnimationFrame(function () {
                watchQueued = false;
                render();
            });
        }).observe(blocksRoot, { childList: true, subtree: true });
    }
    if (data) {
        var queued = false;
        data.subscribe(function () {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                try {
                    render();
                } catch (error) {
                    // Wybór paczkomatu jest dodatkiem: błąd nie może zepsuć płatności.
                }
            });
        });
    }

    render();
})();
