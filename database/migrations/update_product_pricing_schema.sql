-- Add separate pricing fields for bulk and individual purchases

ALTER TABLE products
    ADD COLUMN bulk_purchase_price DECIMAL(10, 2) DEFAULT NULL AFTER purchase_price,
    ADD COLUMN individual_purchase_price DECIMAL(10, 2) DEFAULT NULL AFTER bulk_purchase_price;

-- Migrate existing purchase_price values to individual_purchase_price for existing rows
UPDATE products
SET individual_purchase_price = purchase_price
WHERE individual_purchase_price IS NULL;
