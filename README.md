# MadeIT InPost

Free WooCommerce plugin with an InPost parcel locker shipping method and a map to choose the point at checkout (classic and block). Published on WordPress.org; the optional paid add-on is [madeit-inpost-pro](https://github.com/tobiaszgerczuk/madeit-inpost-pro).

## Extension points for the Pro add-on

Filters: `madeit_inpost_settings`, `madeit_inpost_content`, `madeit_inpost_settings_tabs`, `madeit_inpost_admin_pages`, `madeit_inpost_notice_messages`. Actions: `madeit_inpost_hero`, `madeit_inpost_render_settings_tab`, `madeit_inpost_order_box` (order screen box), `madeit_inpost_point_saved` (a point was saved in a new order). Static helper `Madeit\Inpost\Admin::preview()` renders the live preview.

## Tests

`php tests/calculator.php` checks the shipping cost and point validation without WordPress. `tests/smoke.php` runs with `wp eval-file` in an isolated test shop.

## Release

Bump `Version` in `madeit-inpost.php` and `Stable tag` in `readme.txt`, add a `## X.Y.Z` section to `CHANGELOG.md` and push a `vX.Y.Z` tag. The workflow builds the ZIP, publishes it to the shop downloads and, once the SVN secrets exist, deploys to WordPress.org.
