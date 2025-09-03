# Absuma - Transportation Management System

A PHP-based web application for managing transportation operations, clients, vehicles, drivers, and vendors.

## Features

- Client Management
- Vehicle Management  
- Driver Management
- Vendor Management
- Trip Creation
- Reporting System
- Document Upload System

## Prerequisites

- PHP 7.4 or higher
- MySQL 8.0 or higher
- Web server (Apache/Nginx)

## Installation

### For macOS Users (using Homebrew)

1. **Install PHP and MySQL:**
   ```bash
   brew install php mysql
   ```

2. **Start MySQL service:**
   ```bash
   brew services start mysql
   ```

3. **Set MySQL root password:**
   ```bash
   mysql_secure_installation
   ```

4. **Import database:**
   ```bash
   mysql -u root -p < absuma.sql
   ```

5. **Configure database connection:**
   - Edit `db_connection.php` with your MySQL credentials

6. **Start PHP development server:**
   ```bash
   php -S localhost:8000
   ```

### For Windows Users

1. **Install XAMPP or WAMP:**
   - Download XAMPP from: https://www.apachefriends.org/
   - Or download WAMP from: https://www.wampserver.com/

2. **Start Apache and MySQL services:**
   - Open XAMPP/WAMP control panel
   - Start Apache and MySQL services

3. **Import database:**
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Create a new database named "absuma"
   - Import the `absuma.sql` file

4. **Configure database connection:**
   - Edit `db_connection.php` with your MySQL credentials
   - Default XAMPP credentials: username="root", password=""

5. **Access the application:**
   - Place project files in `htdocs` folder (XAMPP) or `www` folder (WAMP)
   - Navigate to: http://localhost/absuma

## Git Collaboration Setup

### Initial Setup (First Time)

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd absuma
   ```

2. **Create and switch to a feature branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Daily Workflow

1. **Pull latest changes:**
   ```bash
   git pull origin main
   ```

2. **Make your changes and commit:**
   ```bash
   git add .
   git commit -m "Description of your changes"
   ```

3. **Push your changes:**
   ```bash
   git push origin feature/your-feature-name
   ```

4. **Create a Pull Request** (on GitHub/GitLab) to merge your feature branch

### For Windows Users - Git Setup

1. **Install Git for Windows:**
   - Download from: https://git-scm.com/download/win
   - Use default settings during installation

2. **Configure Git (first time only):**
   ```bash
   git config --global user.name "Your Name"
   git config --global user.email "your.email@example.com"
   ```

3. **Use Git Bash or Windows Command Prompt:**
   - Git Bash provides a Unix-like environment
   - All Git commands work the same way

## Project Structure

```
absuma/
├── Uploads/                 # Document uploads
├── add_clients.php         # Add new clients
├── add_vehicle.php         # Add new vehicles
├── auth_check.php          # Authentication middleware
├── client_reports.php      # Client reporting
├── create_trip.php         # Trip creation
├── dashboard.php           # Main dashboard
├── db_connection.php       # Database configuration
├── edit_client.php         # Edit client details
├── edit_vehicle.php        # Edit vehicle details
├── edit_vendor.php         # Edit vendor details
├── header_component.php    # Header template
├── index.php               # Main entry point
├── logout.php              # Logout functionality
├── manage_clients.php      # Client management
├── manage_drivers.php      # Driver management
├── manage_vehicles.php     # Vehicle management
├── manage_vendors.php      # Vendor management
└── vendor_Registration.php # Vendor registration
```

## Database Configuration

Update `db_connection.php` with your database credentials:

```php
<?php
$host = 'localhost';
$username = 'your_username';
$password = 'your_password';
$database = 'absuma';
?>
```

## Contributing

1. Create a feature branch for your changes
2. Make your changes and test thoroughly
3. Commit with descriptive messages
4. Push to your feature branch
5. Create a Pull Request for review

## Support

For any issues or questions, please contact the development team.

## License

This project is proprietary software. All rights reserved.
