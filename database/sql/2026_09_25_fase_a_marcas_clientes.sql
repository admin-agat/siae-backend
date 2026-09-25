-- ============================================================
-- SIAE - Fase A (Marcas + Clientes) - 2026-09-25
-- Correr en PRODUCCIÓN antes del deploy, cada sentencia por separado.
-- ============================================================

-- 1) Alinear secuencias con MAX(id) (evita el 500 por id duplicado)
SELECT setval(pg_get_serial_sequence('brands', 'id'),               (SELECT MAX(id) FROM brands));
SELECT setval(pg_get_serial_sequence('customers', 'id'),            (SELECT MAX(id) FROM customers));
SELECT setval(pg_get_serial_sequence('destinations', 'id'),         (SELECT MAX(id) FROM destinations));
SELECT setval(pg_get_serial_sequence('ports', 'id'),                (SELECT MAX(id) FROM ports));
SELECT setval(pg_get_serial_sequence('port_tariff_items', 'id'),    (SELECT MAX(id) FROM port_tariff_items));
SELECT setval(pg_get_serial_sequence('shipping_lines', 'id'),       (SELECT MAX(id) FROM shipping_lines));
SELECT setval(pg_get_serial_sequence('vessels', 'id'),              (SELECT MAX(id) FROM vessels));
SELECT setval(pg_get_serial_sequence('bookings', 'id'),             (SELECT MAX(id) FROM bookings));
SELECT setval(pg_get_serial_sequence('material_recipes', 'id'),     (SELECT MAX(id) FROM material_recipes));
SELECT setval(pg_get_serial_sequence('producer_quotas', 'id'),      (SELECT MAX(id) FROM producer_quotas));
SELECT setval(pg_get_serial_sequence('producer_quota_lines', 'id'), (SELECT MAX(id) FROM producer_quota_lines));
SELECT setval(pg_get_serial_sequence('supplies', 'id'),             (SELECT MAX(id) FROM supplies));

-- 2) customers: CHECK en mayúsculas y en español (igual que tallies)
ALTER TABLE customers DROP CONSTRAINT customers_negotiation_type_check;
ALTER TABLE customers ADD CONSTRAINT customers_negotiation_type_check
    CHECK (negotiation_type IN ('CONTRATO', 'SPOT'));

-- 3) customers: código único
ALTER TABLE customers ADD CONSTRAINT customers_customer_code_unique UNIQUE (customer_code);
