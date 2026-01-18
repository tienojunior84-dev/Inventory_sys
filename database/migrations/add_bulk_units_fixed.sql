-- Migration: Add Bulk Unit Support
-- Run this script to add bulk unit functionality to existing database
-- For MySQL/MariaDB

USE inventory_sys;

-- Add bulk unit columns to products table (if they don't exist)
ALTER TABLE products 
ADD COLUMN bulk_unit_type VARCHAR(50) DEFAULT NULL COMMENT 'carton, crate, etc.';

ALTER TABLE products 
ADD COLUMN units_per_bulk INT DEFAULT NULL COMMENT 'How many individual units in one bulk unit';

ALTER TABLE products 
ADD COLUMN bulk_unit_label VARCHAR(50) DEFAULT NULL COMMENT 'Display label: Carton, Crate, etc.';

-- Add bulk quantity columns to stock_movements table (if they don't exist)
ALTER TABLE stock_movements
ADD COLUMN bulk_quantity DECIMAL(10, 2) DEFAULT NULL COMMENT 'Quantity in bulk units';

ALTER TABLE stock_movements
ADD COLUMN bulk_cost DECIMAL(10, 2) DEFAULT NULL COMMENT 'Cost per bulk unit';

-- Add receipt number to stock_movements for receipt tracking
ALTER TABLE stock_movements
ADD COLUMN receipt_number VARCHAR(50) DEFAULT NULL COMMENT 'Unique receipt number';

-- Add index for receipt number
ALTER TABLE stock_movements
ADD INDEX idx_receipt_number (receipt_number);
