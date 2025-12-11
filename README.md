# SlimStorage

A secure, high-performance key/value store and event API built with PHP 8.1+ and MySQL. Features Google OAuth authentication, granular API key management, real-time usage monitoring, and comprehensive security.

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![MySQL Version](https://img.shields.io/badge/mysql-%3E%3D5.7-4479A1.svg)](https://www.mysql.com/)

## Features

- **🔐 Secure Authentication**: Google OAuth 2.0 for admin access, 64-char cryptographically secure API keys
- **🚀 High Performance**: Optimized MySQL queries, indexed lookups, pre-computed statistics
- **📊 Usage Monitoring**: Real-time tracking of API requests (daily/weekly/monthly) per key
- **🛡️ Robust Security**: Rate limiting, CSRF protection, XSS prevention, strict input validation
- **👤 Modern Dashboard**: Beautiful admin interface with real-time analytics and charts
- **📦 Isolated Storage**: Each API key has its own private namespace
- **⚡ Extended Operations**: GET, SET, DELETE, EXISTS, LIST, CLEAR
- **📡 Event Data API**: Time-series data storage with millisecond precision and date range queries
- **🔍 Schema Optimization**: Define schemas for sub-millisecond aggregation queries on millions of events
- **🛠️ Easy Deployment**: One-file installer with automatic updates

## Quick Start

### One-File Installation (Recommended)

The easiest way to install SlimStorage - just one file!

1. **Download the installer**:
   ```bash
   wget https://github.com/kibotu/SlimStorage/releases/latest/download/install.php
   ```

2. **Upload to your server** (via FTP, SFTP, or copy directly)

3. **Open in your browser**:
   ```
   https://yourdomain.com/install.php
   ```

4. **Follow the wizard** - it will:
   - Check system requirements
   - Download the latest release automatically
   - Extract all files
   - Guide you through configuration
   - Set up the database
   - Complete the installation

5. **Delete the installer** for security

That's it! 🎉

### Updating an Existing Installation

```bash
# 1. Download the installer
wget https://github.com/kibotu/SlimStorage/releases/latest/download/install.php

# 2. Upload to your existing SlimStorage directory

# 3. Open with update flag
# https://yourdomain.com/install.php?update=1
```

Your `.secrets.yml` and database will be preserved during updates.

## Requirements

- **PHP**: 8.1 or higher
- **MySQL**: 5.7+ or MariaDB 10.3+
- **PHP Extensions**:
  - PDO MySQL (`pdo_mysql`)
  - cURL (`curl`)
  - JSON (`json`)
  - ZipArchive (`zip`) - for installer
  - OpenSSL (`openssl`)

## API Documentation

### Base URL

```
https://yourdomain.com/api/
```

### Authentication

All API requests require an API key in the header:

```bash
X-API-Key: your_64_character_api_key_here
Content-Type: application/json
```

### Key/Value Store API

| Endpoint | Method | Body | Description |
|----------|--------|------|-------------|
| `/set` | POST | `{"value": "data"}` | Store a value, returns generated UUID key |
| `/set` | POST | `{"key": "uuid", "value": "data"}` | Update existing key or create if not exists |
| `/get` | POST | `{"key": "uuid"}` | Retrieve a value |
| `/exists` | POST | `{"key": "uuid"}` | Check if key exists |
| `/delete` | POST | `{"key": "uuid"}` | Delete a key |
| `/list` | GET | - | List all keys |
| `/clear` | DELETE | - | Delete all keys |

**Example:**

```bash
# Store a new value
curl -X POST "https://yourdomain.com/api/set" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"value": "Hello World"}'

# Response: {"status": "success", "key": "a7469afb-8ad2-4a75-b565-1a931aa2ad0d"}

# Get a value
curl -X POST "https://yourdomain.com/api/get" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"key": "a7469afb-8ad2-4a75-b565-1a931aa2ad0d"}'
```

### Event Data API

Perfect for IoT, analytics, monitoring, and time-series data.

| Endpoint | Method | Body | Description |
|----------|--------|------|-------------|
| `/event/push` | POST | `{"data": {...}, "timestamp": "..."}` | Store event(s), max 1000/request |
| `/event/query` | POST | `{"start_date": "...", "end_date": "...", "limit": 100}` | Query by date range |
| `/event/aggregate` | POST | `{"granularity": "hourly"}` | Query pre-aggregated data |
| `/event/stats` | GET | - | Get statistics & daily counts |
| `/event/clear` | DELETE | - | Delete all events for this API key |

**Example:**

```bash
# Push event (uses current timestamp)
curl -X POST "https://yourdomain.com/api/event/push" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"data": {"temperature": 23.5, "humidity": 65}}'

# Push event with custom timestamp
curl -X POST "https://yourdomain.com/api/event/push" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"data": {"temperature": 23.5}, "timestamp": "2024-12-01T14:30:00Z"}'

# Query events
curl -X POST "https://yourdomain.com/api/event/query" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"start_date": "2024-12-01T00:00:00Z", "limit": 100}'
```

### Schema API (Event Optimization)

Define schemas for your event data to enable **sub-millisecond aggregation queries** on millions of events.

| Endpoint | Method | Body | Description |
|----------|--------|------|-------------|
| `/schema` | POST | `{"fields": [...], "aggregations": [...]}` | Define schema for this API key |
| `/schema` | GET | - | Get current schema and aggregation status |
| `/schema` | DELETE | - | Remove schema (keeps raw events) |
| `/schema/rebuild` | POST | - | Rebuild aggregation tables from raw events |

**Example:**

```bash
# Define schema for sensor data
curl -X POST "https://yourdomain.com/api/schema" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "fields": [
      {"name": "temperature", "type": "float"},
      {"name": "humidity", "type": "integer"}
    ],
    "aggregations": ["hourly", "daily"]
  }'

# Query aggregated data (O(hours) instead of O(millions))
curl -X POST "https://yourdomain.com/api/event/aggregate" \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"granularity": "hourly", "start_date": "2024-12-01", "end_date": "2024-12-10"}'
```

**Performance Comparison (11M events):**

| Query Type | Without Schema | With Schema |
|------------|----------------|-------------|
| Last 24h (hourly) | 3-5 seconds | <1ms |
| Last 30 days (daily) | 5-10 seconds | <1ms |
| All-time stats | timeout | <5ms |

## Admin Dashboard

Access the dashboard at `https://yourdomain.com/`

### Features

- **🔑 Dashboard Tab**: Generate, rename, and delete API keys with usage statistics
- **📚 Documentation Tab**: Complete API reference with interactive examples
- **🧪 API Playground**: Test API endpoints directly from the dashboard
- **🗄️ View Data Tab**: Browse and manage all stored key-value pairs
- **📡 Event Explorer**: Query and visualize time-series event data
- **🛡️ Superadmin**: System-wide analytics and user management (if configured)

### Global API Key Filter

- Single dropdown that applies to all tabs
- Persistent selection across page reloads
- Auto-updates code examples and data views
- Shows filtered item counts

## Configuration

Configuration is stored in `.secrets.yml` (created during installation):

```yaml
# Database Configuration
database:
  host: localhost
  port: 3306
  name: your_database_name
  user: your_database_user
  password: your_database_password
  prefix: slimstore_

# Domain Configuration
domain:
  name: yourdomain.com

# Google OAuth Configuration
google_oauth:
  client_id: YOUR_CLIENT_ID.apps.googleusercontent.com
  client_secret: YOUR_CLIENT_SECRET
  redirect_uri: https://yourdomain.com/admin/callback.php

# Superadmin Configuration
superadmin:
  email: admin@example.com

# API Configuration
api:
  rate_limit_requests: 10000
  rate_limit_window_seconds: 60
  max_keys_per_user: 100
  max_value_size_bytes: 262144
  allowed_origins: https://yourdomain.com
```

### Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google+ API
4. Create OAuth 2.0 credentials
5. Add authorized redirect URI: `https://yourdomain.com/admin/callback.php`
6. Copy Client ID and Client Secret to `.secrets.yml`

## Manual Installation

If you prefer not to use the installer:

1. **Download the latest release**:
   ```bash
   wget https://github.com/kibotu/SlimStorage/releases/latest/download/slimstore-vX.X.X.zip
   unzip slimstore-vX.X.X.zip
   ```

2. **Create `.secrets.yml`** (see `.secrets-sample.yml` for template)

3. **Import database schema**:
   ```bash
   mysql -u your_user -p your_database < schema.sql
   ```

4. **Upload files to your server**

5. **Configure web server** (see below)

### Web Server Configuration

**Apache** (`.htaccess` included):
```apache
# Already configured in public/.htaccess
```

**Nginx**:
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /path/to/slimstore/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~ /\.secrets\.yml$ {
        deny all;
    }
}
```

## Security

- **Authentication**: Google OAuth 2.0 and high-entropy API keys
- **Input/Output**: Full input validation, sanitization, and output encoding
- **Database**: 100% PDO prepared statements (SQL injection protection)
- **Session**: Secure, HttpOnly, SameSite cookies with automatic regeneration
- **Headers**: Strict security headers (X-Frame-Options, CSP, HSTS)
- **Self-Hosted Assets**: All fonts and libraries hosted locally

### Security Best Practices

- ✅ Always use HTTPS in production
- ✅ Keep `.secrets.yml` secure (never commit to git)
- ✅ Delete `install.php` after installation
- ✅ Regularly backup your database
- ✅ Keep PHP and MySQL updated
- ✅ Use strong passwords
- ✅ Monitor API logs for suspicious activity

## Performance

The system uses pre-computed statistics tables for instant dashboard load times:

- **Event statistics**: O(1) instead of O(millions)
- **KV statistics**: O(1) instead of O(pairs)
- **Timeline queries**: O(days) instead of O(events)
- **Request logs**: O(days) instead of O(logs)

**Real-World Performance (8M+ events, 100K+ logs):**

| Dashboard Section | Before | After | Improvement |
|-------------------|--------|-------|-------------|
| Event Statistics | 5-10s | <10ms | **500-1000x faster** |
| Common Event Keys | 3-5s | <5ms | **600-1000x faster** |
| KV Storage Stats | 1-2s | <5ms | **200-400x faster** |
| Timeline Chart | 5-8s | <10ms | **500-800x faster** |

## Database Schema

- `slimstore_api_keys` - API credentials
- `slimstore_kv_store` - Key/value pairs (16MB per value)
- `slimstore_events` - Time-series events with JSON data
- `slimstore_event_stats` - Pre-computed daily aggregates
- `slimstore_api_key_stats` - Aggregate statistics per API key
- `slimstore_event_key_stats` - Common event keys
- `slimstore_api_logs` - API usage logs
- `slimstore_api_logs_stats` - Pre-computed request statistics
- `slimstore_event_schemas` - User-defined field schemas
- `slimstore_event_aggregations` - Aggregation table status
- `slimstore_sessions` - Admin session management
- `slimstore_rate_limits` - IP-based rate limiting

## Development

### File Structure

```
SlimStorage/
├── public/              # Web root
│   ├── api/            # API endpoints
│   ├── admin/          # Admin dashboard
│   ├── config.php      # Configuration loader
│   ├── security.php    # Security functions
│   └── session.php     # Session management
├── scripts/            # Deployment and testing
├── schema.sql          # Database schema
├── install.php         # One-file installer
└── .secrets-sample.yml # Configuration template
```

### Testing

```bash
# Run all tests
./scripts/run-tests.sh https://yourdomain.com/api YOUR_API_KEY
```

## Troubleshooting

### Common Issues

**Database connection failed**
- Verify credentials in `.secrets.yml`
- Check MySQL is running
- Ensure database exists

**Google OAuth errors**
- Verify redirect URI matches exactly
- Check OAuth consent screen is configured
- Add test users if in testing mode

**Permission errors**
- Ensure web server can write to installation directory
- Check `.secrets.yml` has 600 permissions

**500 Internal Server Error**
- Check PHP error logs
- Verify PHP version is 8.1+
- Ensure all required extensions are installed

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 🎭 Live Demo

Check out the static preview of SlimStorage's UI with mock data:

**[👉 View Live Demo](https://kibotu.github.io/SlimStorage/)**

The demo includes:
- **Landing Page** - Google OAuth login interface
- **Admin Dashboard** - API key management, data explorer, event tracking, API playground
- **Superadmin Panel** - System-wide analytics, user management, usage insights

> Note: This is a static preview with fake data. No actual functionality is available in the demo.

### Running the Preview Locally

To view the preview locally:

```bash
# Navigate to the preview directory
cd preview

# Serve with any static file server
python -m http.server 8000
# or
npx serve .
```

Then open `http://localhost:8000` in your browser.

## License

[Apache License 2.0](LICENSE)

## Support

- 🐛 [Report Issues](https://github.com/kibotu/SlimStorage/issues)
- 💬 [Discussions](https://github.com/kibotu/SlimStorage/discussions)
- 📖 [Documentation](https://github.com/kibotu/SlimStorage)

## Acknowledgments

Built with ❤️ for developers who need a simple, secure, and fast key/value store and event API.

---

**Made by [kibotu](https://github.com/kibotu)**

