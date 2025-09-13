# Employee Schedule System Setup Guide

## Overview
The employee schedule system has been enhanced to allow employees to create and manage schedules for appointments and hearings, similar to the attorney schedule system. Employees can now choose from registered attorneys and admins when creating schedules.

## New Features

### 1. Schedule Creation
- Employees can create new events (appointments/hearings)
- Select from registered attorneys and admins
- Choose related cases (optional)
- Set event details (title, date, time, location, description)

### 2. User Selection
- **Attorney Selection**: Choose from registered attorneys
- **Admin Selection**: Choose from registered admins
- Dynamic dropdown that updates based on selected user type

### 3. Enhanced Event Management
- View all created events in a calendar view
- Update event statuses (scheduled, completed, no-show, rescheduled, cancelled)
- View detailed event information
- Professional event cards with status indicators

## Database Changes Required

### 1. Add New Column
Run the following SQL script to add the required column:

```sql
-- Execute the contents of add_employee_schedule_column.sql
ALTER TABLE `case_schedules` 
ADD COLUMN `created_by_employee_id` int(11) DEFAULT NULL AFTER `location`;

-- Add index for better performance
ALTER TABLE `case_schedules` 
ADD INDEX `idx_created_by_employee` (`created_by_employee_id`);
```

### 2. Table Structure
The `case_schedules` table now includes:
- `created_by_employee_id`: Tracks which employee created the schedule
- Existing fields remain unchanged

## File Changes

### 1. Modified Files
- `employee_schedule.php` - Enhanced with full schedule management
- `update_event_status.php` - Updated to allow employee access

### 2. New Files
- `add_employee_schedule_column.sql` - Database setup script
- `EMPLOYEE_SCHEDULE_SETUP.md` - This setup guide

## Setup Instructions

### Step 1: Database Update
1. Open your database management tool (phpMyAdmin, MySQL Workbench, etc.)
2. Execute the SQL commands from `add_employee_schedule_column.sql`
3. Verify the new column was added successfully

### Step 2: Test the System
1. Log in as an employee
2. Navigate to the Schedule page
3. Try creating a new event
4. Test the attorney/admin selection dropdowns
5. Verify event creation and status updates

## User Interface Features

### 1. Add Event Modal
- Professional form layout
- Dynamic user type selection
- Case association (optional)
- Form validation

### 2. Event Display
- Calendar view with FullCalendar.js
- Event cards showing:
  - Event type and title
  - Case information
  - Client details
  - Attorney/Admin assignment
  - Date, time, and location
  - Status management

### 3. Status Management
- Dropdown to change event status
- Confirmation dialogs with warnings
- Visual status indicators
- Audit trail logging

## Security Features

### 1. Access Control
- Only employees can access the enhanced schedule system
- Session validation for all operations
- User type verification

### 2. Data Validation
- Input sanitization
- SQL injection prevention
- Status value validation

### 3. Audit Logging
- All status changes are logged
- User action tracking
- Error logging for debugging

## Troubleshooting

### Common Issues

1. **"Column not found" error**
   - Ensure you've run the database update script
   - Check if the column was added successfully

2. **Permission denied errors**
   - Verify user session is valid
   - Check user type is 'employee'
   - Ensure proper database permissions

3. **Dropdown not populating**
   - Check if attorneys/admins exist in the database
   - Verify the SQL query in the PHP code
   - Check browser console for JavaScript errors

### Debug Mode
Enable error reporting in PHP to see detailed error messages:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Browser Compatibility
- Modern browsers (Chrome, Firefox, Safari, Edge)
- FullCalendar.js 5.11.3+
- Font Awesome 6.0.0+
- Responsive design for mobile devices

## Performance Considerations
- Database indexes added for better query performance
- Efficient SQL queries with proper JOINs
- Client-side form validation
- AJAX requests for smooth user experience

## Future Enhancements
- Email notifications for scheduled events
- Calendar export functionality
- Recurring event support
- Conflict detection for overlapping schedules
- Integration with external calendar systems

## Support
For technical support or questions about the employee schedule system, please refer to the system documentation or contact the development team.
