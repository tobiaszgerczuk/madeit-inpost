# Madeit InPost

Wybór paczkomatu InPost na mapie w checkoucie WooCommerce (klasycznym i blokowym) oraz tworzenie przesyłek i etykiet przez ShipX.

## Funkcje

- Metoda wysyłki „Paczkomat InPost” w strefach wysyłki: koszt i próg darmowej dostawy.
- Wybór punktu na mapie InPost Geowidget; bez tokenu mapy klient wpisuje kod punktu.
- Punkt zapisany w zamówieniu, widoczny w panelu, na stronie zamówienia i w e-mailach.
- W zamówieniu: utworzenie przesyłki ShipX, status, numer przesyłki i etykieta PDF (A6).
- Pobranie (COD) przekazywane do ShipX, gdy zamówienie ma płatność przy odbiorze.

## Konfiguracja

1. WooCommerce → Ustawienia → Wysyłka → strefa → dodaj „Paczkomat InPost (Madeit)”.
2. Madeit InPost → Połączenie: token mapy (Geowidget), identyfikator organizacji i token API ShipX z Managera Paczek.
3. Zakładka Zakres: domyślny gabaryt i sposób nadania.

## Testy

```
php tests/calculator.php
```

Pełny test: skopiuj wtyczkę do demo WooCommerce i sprawdź checkout oraz zamówienie.
