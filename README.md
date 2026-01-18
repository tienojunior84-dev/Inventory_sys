# Inventory Management System MVP

A comprehensive inventory management system for tracking products across three categories: Provisions, Wine, and Beer.

## Features

### Authentication
- User registration and login
- Session management
- Password change functionality
- Protected routes

### Product Management
- Add, edit, and delete products
- Three categories: Provisions, Wine, Beer
- Track purchase price, selling price, stock levels
- Set reorder levels for low stock alerts

### Stock Management
- Receive inventory (Stock In)
- Automatic stock quantity updates
- Stock movement history tracking
- Low stock alerts

### Sales Recording
Three flexible methods:
1. **Manual Entry** - Single product sales entry
2. **Batch Entry** - Multiple products in one transaction
3. **Excel Upload** - Bulk upload via CSV/Excel file

### Reports & Analytics
- Sales reports by date range
- Profit calculations
- Category performance analysis
- Best selling products (overall and per category)
- Slow moving items identification
- Inventory reports by category
- Low stock alerts

### Dashboard
- Today's sales summary
- Inventory value by category
- Low stock count
- Recent sales list

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache web server (XAMPP recommended)
- Modern web browser

## Installation

1. **Clone or extract the project** to your web server directory:
   ```
   C:\xampp\htdocs\Inventory_sys
   ```

2. **Create the database**:
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Import the schema file: `database/schema.sql`
   - Or run the SQL file manually

3. **Configure database connection** (if needed):
   - Edit `config/database.php`
   - Update DB_HOST, DB_USER, DB_PASS, DB_NAME if different from defaults

4. **Set permissions**:
   - Ensure `public/uploads/` directory is writable
   - On Windows, this should work by default

5. **Access the application**:
   - Open browser: http://localhost/Inventory_sys
   - Register a new account
   - Start using the system!

## Default Configuration

- Database Host: localhost
- Database User: root
- Database Password: (empty)
- Database Name: inventory_sys

## File Structure

```
Inventory_sys/
├── api/              # API endpoints
├── config/           # Configuration files
├── database/         # Database schema
├── includes/         # Shared PHP files
├── pages/            # Main application pages
│   ├── auth/        # Authentication pages
│   └── ...
├── public/           # Public assets
│   ├── css/         # Stylesheets
│   ├── js/          # JavaScript files
│   └── uploads/     # File uploads directory
└── index.php        # Entry point
```

## Usage Guide

### Initial Setup
1. Register a new account
2. Login to the system
3. Add products to your catalog
4. Set initial stock quantities and reorder levels

### Daily Operations
- **Stock In**: Receive new inventory deliveries
- **Record Sales**: Use any of the three methods to record sales
- **Dashboard**: Monitor daily performance
- **Reports**: Analyze sales and inventory data

### Weekly/Monthly Review
- Review category performance reports
- Identify best sellers per category
- Check slow moving items
- Plan restocking based on data

## Excel Upload Template

When using Excel upload, the CSV file should have the following format:
```
Product Name,Quantity,Date
Example Product,5,2024-01-15
Another Product,10,2024-01-15
```

- Product Name must match exactly (case-insensitive)
- Quantity must be a positive integer
- Date format: YYYY-MM-DD (optional, defaults to today)

## Security Features

- Password hashing with bcrypt
- SQL injection prevention (prepared statements)
- XSS protection (input sanitization)
- Session-based authentication
- File upload validation

## Support

For issues or questions, please check:
- Database connection settings
- File permissions
- PHP error logs
- Browser console for JavaScript errors

## License

This is a custom inventory management system built for internal use.
