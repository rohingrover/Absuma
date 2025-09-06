# Absuma SQL Queries Documentation

This file contains all SQL queries that need to be executed for the Absuma Logistics system. Execute these queries in chronological order to set up the database properly.

## Date: September 6, 2025

### 1. Create Bookings Table
**Purpose**: Create a new table for managing container bookings by Manager 1 users.

```sql
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id VARCHAR(50) UNIQUE NOT NULL,
    client_id INT NOT NULL,
    no_of_containers INT NOT NULL,
    container_type VARCHAR(100) NOT NULL,
    container_number VARCHAR(100) NULL,
    from_location_id INT NULL,
    to_location_id INT NULL,
    status ENUM('pending', 'confirmed', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (from_location_id) REFERENCES location(id),
    FOREIGN KEY (to_location_id) REFERENCES location(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

**Note**: Booking ID format is AB-YYYY-XXX (e.g., AB-2025-001, AB-2025-002...)

### 1.1. Create Booking Containers Table (Updated)
**Purpose**: Create a separate table for individual container details within each booking.

```sql
CREATE TABLE booking_containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    container_sequence INT NOT NULL,
    container_type ENUM('20ft', '40ft') NOT NULL,
    container_number_1 VARCHAR(50) NULL,
    container_number_2 VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_booking_sequence (booking_id, container_sequence)
);
```

### 1.2. Update Bookings Table Structure
**Purpose**: Remove container-specific fields from main bookings table since they're now in booking_containers.

```sql
ALTER TABLE bookings 
DROP COLUMN container_type,
DROP COLUMN container_number;
```

### 2. Update Users Table for New Role Structure
**Purpose**: Update user roles to include hierarchical management structure.

```sql
ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','manager1','manager2','l1_supervisor','l2_supervisor','staff') NOT NULL DEFAULT 'staff';
```

### 3. Update Admin User to Superadmin Role
**Purpose**: Grant superadmin privileges to the existing admin user.

```sql
UPDATE users SET role = 'superadmin' WHERE username = 'admin';
```

---

## Date: September 6, 2025 (Updated)

### 4. Database Migration for Multiple Container Support
**Purpose**: Update database structure to properly handle multiple containers with different types.

**IMPORTANT**: Execute these queries in order if you already have the bookings table created.

```sql
-- Step 1: Create the new booking_containers table
CREATE TABLE booking_containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    container_sequence INT NOT NULL,
    container_type ENUM('20ft', '40ft') NOT NULL,
    container_number_1 VARCHAR(50) NULL,
    container_number_2 VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_booking_sequence (booking_id, container_sequence)
);

-- Step 2: Remove old container fields from bookings table
ALTER TABLE bookings 
DROP COLUMN container_type,
DROP COLUMN container_number;
```

**Benefits of New Structure:**
- ✅ **Proper normalization**: Each container is a separate record
- ✅ **Data integrity**: Clear relationship between booking and containers
- ✅ **Flexible queries**: Easy to search/filter by container type or number
- ✅ **Scalable**: Can handle any number of containers per booking
- ✅ **Maintainable**: Easy to update individual container details


---

## Date: September 6, 2025 (Location Management Update)

### 5. Update Existing Locations with Admin User
**Purpose**: Set all existing locations to have admin as the updated_by user for proper user tracking.

```sql
UPDATE location 
SET updated_by = 1, updated_at = NOW() 
WHERE updated_by = 0 OR updated_by IS NULL;
```

**Note**: This updates all existing locations to show "admin" as the user who last updated them, providing proper user tracking for the location management system.

---

*Last Updated: September 6, 2025*
*Maintained by: Rohin*

---

## Date: September 7, 2025 (Per-Container Locations)

### 6. Add per-container From/To locations on booking_containers
**Purpose**: Allow each container to have its own from/to location, while still supporting a global booking-level from/to.

```sql
-- Step 1: Add new columns (safe to run multiple times if you guard in client)
ALTER TABLE booking_containers ADD COLUMN from_location_id INT NULL;
ALTER TABLE booking_containers ADD COLUMN to_location_id INT NULL;

-- Step 2: Add FKs (optional if your MySQL version supports IF NOT EXISTS; otherwise, check first)
CREATE INDEX idx_bc_from_location_id ON booking_containers(from_location_id);
CREATE INDEX idx_bc_to_location_id   ON booking_containers(to_location_id);

ALTER TABLE booking_containers
  ADD CONSTRAINT fk_bc_from_location FOREIGN KEY (from_location_id) REFERENCES location(id),
  ADD CONSTRAINT fk_bc_to_location   FOREIGN KEY (to_location_id)   REFERENCES location(id);

-- Step 3: Backfill container rows with booking-level locations where missing
UPDATE booking_containers bc
JOIN bookings b ON bc.booking_id = b.id
SET 
    bc.from_location_id = COALESCE(bc.from_location_id, b.from_location_id),
    bc.to_location_id   = COALESCE(bc.to_location_id, b.to_location_id)
WHERE bc.from_location_id IS NULL OR bc.to_location_id IS NULL;
```

### 6.1. Modify Container Types Column to Allow NULL
**Purpose**: Set container type to allow null values to accommodate bookings without specific container information.

```sql
ALTER TABLE booking_containers
  MODIFY COLUMN container_type ENUM('20ft','40ft') NULL DEFAULT NULL;
```

**Notes**:
- If you run this multiple times, make sure to keep existing values or adjust their type.



ALTER TABLE booking_containers
  MODIFY COLUMN container_type ENUM('20ft','40ft') NULL DEFAULT NULL;

**Notes**:
- If your MySQL/MariaDB version does not support "IF NOT EXISTS" on ADD COLUMN/ADD CONSTRAINT, first check column/constraint existence and conditionally run the statements.
- UI will offer a "Same locations for all containers" option that copies the global selection to each container row.

---

## Date: September 7, 2025 (Audit columns)

### 7. Add created_by and updated_by

```sql
-- bookings: add updated_by (created_by already exists)
ALTER TABLE bookings 
  ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
  ADD CONSTRAINT IF NOT EXISTS fk_bookings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id);

-- booking_containers: add created_by and updated_by
ALTER TABLE booking_containers 
  ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
  ADD CONSTRAINT IF NOT EXISTS fk_bc_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  ADD CONSTRAINT IF NOT EXISTS fk_bc_updated_by FOREIGN KEY (updated_by) REFERENCES users(id);
```
