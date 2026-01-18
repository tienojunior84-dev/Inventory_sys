-- Migration: Add Bulk Unit Support
-- Run this script to add bulk unit functionality to existing database

USE inventory_sys;

-- Add bulk unit columns to products table
ALTER TABLE products 
ADD COLUMN IF NOT EXISTS bulk_unit_type VARCHAR(50) DEFAULT NULL COMMENT 'carton, crate, etc.',
ADD COLUMN IF NOT EXISTS units_per_bulk INT DEFAULT NULL COMMENT 'How many individual units in one bulk unit',
ADD COLUMN IF NOT EXISTS bulk_unit_label VARCHAR(50) DEFAULT NULL COMMENT 'Display label: Carton, Crate, etc.';

-- Add bulk quantity columns to stock_movements table
ALTER TABLE stock_movements
ADD COLUMN IF NOT EXISTS bulk_quantity DECIMAL(10, 2) DEFAULT NULL COMMENT 'Quantity in bulk units',
ADD COLUMN IF NOT EXISTS bulk_cost DECIMAL(10, 2) DEFAULT NULL COMMENT 'Cost per bulk unit';

-- Add receipt number to stock_movements for receipt tracking
ALTER TABLE stock_movements
ADD COLUMN IF NOT EXISTS receipt_number VARCHAR(50) DEFAULT NULL COMMENT 'Unique receipt number',
ADD INDEX IF NOT EXISTS idx_receipt_number (receipt_number);
