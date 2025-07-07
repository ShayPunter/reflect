# Daily Reflection App

A minimalist web application for daily self-reflection, designed to help you track patterns and improve incrementally through consistent introspection.

⚠️ **AI Generated Project**: This project was created using AI assistance (Claude by Anthropic) and may require customization for production use.

## Features

- **Daily Reflection Questionnaire**: Four focused questions to guide your reflection
- **Distraction-Free Interface**: Clean black and white design with minimal UI
- **Once-Per-Day Completion**: Prevents multiple submissions on the same day
- **Streak Tracking**: Monitor your consistency with reflection streaks
- **Data Export/Import**: JSON, CSV, and text format support
- **Daily Notifications**: Optional browser reminders
- **Server Storage**: PHP backend with MySQL database

## Questions

1. What went well?
2. What didn't go well?
3. Any surprises?
4. How could you handle that differently next time?

## Installation

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Web server (Apache/Nginx)

### Setup

1. **Clone/Download the files**
   ```bash
   # Download the HTML file and PHP backend files
   # Place them in your web server directory
   ```

2. **Database Setup**
   ```sql
   CREATE DATABASE reflections;
   CREATE USER 'reflection_user'@'localhost' IDENTIFIED BY 'your_secure_password';
   GRANT ALL PRIVILEGES ON reflections.* TO 'reflection_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Environment Configuration**
   ```bash
   cp .env.example .env
   ```

   Edit `.env` with your database credentials:
   ```bash
   DB_HOST=localhost
   DB_NAME=reflections
   DB_USER=reflection_user
   DB_PASS=your_secure_password
   API_KEY=your_random_api_key_here
   ```

4. **File Structure**
   ```
   your-domain.com/
   ├── index.html          # Main application
   ├── api.php            # Backend API
   ├── config.php         # Configuration loader
   └── .env              # Environment variables
   ```

5. **Web Server Configuration**

   **Apache (.htaccess)**
   ```apache
   RewriteEngine On
   RewriteRule ^api/reflections$ api.php [L,QSA]
   
   <Files ".env">
       Order allow,deny
       Deny from all
   </Files>
   ```

   **Nginx**
   ```nginx
   location /api/reflections {
       try_files $uri /api.php$is_args$args;
   }
   
   location ~ /\.env {
       deny all;
   }
   ```

6. **Update Frontend**

   In the HTML file, update the API endpoint:
   ```javascript
   this.apiEndpoint = '/api/reflections'; // or 'https://yourdomain.com/api/reflections'
   ```

## API Endpoints

### GET /api/reflections
Retrieve all reflections for the current user
```json
{
  "reflections": [...],
  "lastCompleted": "Mon Jul 07 2025"
}
```

### POST /api/reflections
Save a new reflection
```json
{
  "date": "2025-07-07T10:00:00Z",
  "dateString": "Mon Jul 07 2025",
  "wentWell": "Completed morning workout",
  "didntGoWell": "Procrastinated on important task",
  "surprises": "Unexpected call from old friend",
  "nextTime": "Set specific time blocks for focused work"
}
```

### DELETE /api/reflections?id=123
Delete a specific reflection

### DELETE /api/reflections
Delete all reflections for current user

## User Identification

The API identifies users through:
1. `Authorization` header (Bearer token)
2. `X-User-ID` header
3. Anonymous hash of IP + User Agent (fallback)

For production, implement proper authentication:
```javascript
// Add to your frontend requests
headers: {
    'Authorization': 'Bearer ' + userToken,
    'Content-Type': 'application/json'
}
```

## Security Considerations

- **Environment Variables**: Never commit `.env` to version control
- **Database Security**: Use strong passwords and limit user privileges
- **Input Validation**: The API validates all inputs
- **CORS**: Configure `CORS_ORIGIN` for your domain
- **HTTPS**: Always use HTTPS in production
- **Authentication**: Implement proper user authentication for multi-user scenarios

## Customization

### Styling
The app uses a minimal black and white theme. Modify the CSS in the HTML file to match your preferences.

### Questions
Update the reflection questions by modifying the form labels in the HTML file.

### Notifications
Default notification time is 8 PM. Modify in the JavaScript:
```javascript
next.setHours(20, 0, 0, 0); // Change to your preferred time
```

## Data Export/Import

- **JSON**: Full data with metadata
- **CSV**: Spreadsheet-compatible format
- **Text**: Human-readable format

All exports include date, responses, and metadata for pattern analysis.

## Troubleshooting

### Database Connection Issues
1. Verify database credentials in `.env`
2. Check if MySQL service is running
3. Ensure database and user exist
4. Test connection with `mysql -u username -p`

### CORS Errors
1. Check `CORS_ORIGIN` in `.env`
2. Verify web server configuration
3. Ensure API endpoint is correct

### Notification Issues
1. HTTPS required for notifications in most browsers
2. Check browser notification permissions
3. Test in different browsers

### File Permissions
```bash
chmod 644 *.php *.html
chmod 600 .env
```

## Development

### Local Development
```bash
# PHP built-in server
php -S localhost:8000

# Or with specific directory
cd /path/to/project
php -S localhost:8000
```

### Database Schema
The API automatically creates the required table:
```sql
CREATE TABLE reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    date DATETIME NOT NULL,
    date_string VARCHAR(255) NOT NULL,
    went_well TEXT,
    didnt_go_well TEXT,
    surprises TEXT,
    next_time TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Contributing

This is an AI-generated project designed for personal use. Feel free to fork and modify according to your needs.

## License

This project is provided as-is for personal use. Modify and distribute as needed.

## Support

Since this is an AI-generated project, support is limited. For issues:
1. Check the troubleshooting section
2. Verify your environment configuration
3. Test with a minimal setup
4. Check server logs for detailed error messages

## Privacy

- Data is stored locally on your server
- No third-party services involved
- User identification is minimal and can be anonymous
- Export your data anytime for full control

---

**Note**: This project was generated with AI assistance and should be reviewed and tested before production deployment.