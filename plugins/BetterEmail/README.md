# BetterEmail — MantisBT Plugin

Replaces MantisBT's plain-text notification emails with clean, responsive HTML emails styled after JIRA/Atlassian notifications.

---

## Features

- JIRA-style HTML layout: action banner, metadata grid, comment, attachments, change history
- Responsive design with Outlook-safe image grid (`align="left"` float tables)
- Image thumbnails: 200×200 square-cropped JPEGs embedded as base64 data URIs (no external dependencies)
- Thumbnail disk cache scoped to the current send batch (generated once, reused per recipient, purged at process shutdown)
- Smart content rules:
  - **New issue emails** — shows description, steps to reproduce, additional information
  - **Note / update emails** — shows only the latest comment and any newly attached files
  - Attachments section only appears when files were attached in the current update
- Plain-text `AltBody` structured for [EmailReporting](https://github.com/mantisbt-plugins/EmailReporting) compatibility (reply-above separator parsed by EmailReplyParser)

---

## How It Works

### Hook

MantisBT fires `EVENT_EMAIL_CREATE_SEND_PROVIDER` (type `EVENT_TYPE_FIRST`) when it is about to send each queued message. The plugin returns a custom `BetterEmailSender` instance which replaces the core sender for that message.

```
EVENT_EMAIL_CREATE_SEND_PROVIDER
  └─ BetterEmailPlugin::create_sender()
       └─ returns new BetterEmailSender()
```

### Send flow (per queued message = per recipient)

```
BetterEmailSender::send(EmailMessage)
  ├─ extract_bug_id()          — parse bug ID from subject "[Project 0000123]:"
  ├─ BetterEmailPlugin::build_html(plain_text, bug_id)
  │     ├─ parse_action_title()     — first non-separator line (e.g. "A note has been added")
  │     ├─ parse_bugnotes()         — note blocks between "--- ---" separators
  │     ├─ parse_history()          — "Bug History" table rows
  │     ├─ parse_attached_filenames() — "Attached Files:" block filenames
  │     ├─ bug_get() + API calls    — live metadata from DB
  │     ├─ is_new_issue detection   — regex on action title
  │     ├─ render_bug_card()        — full HTML card
  │     │     └─ render_attachments()
  │     │           └─ make_thumbnail_data_uri()  — GD resample + disk cache
  │     └─ render_email_shell()     — DOCTYPE wrapper + CSS
  ├─ PHPMailer::isHTML(true)
  ├─ Body    = HTML output
  └─ AltBody = reply-separator + original plain text
```

### Thumbnail cache

Thumbnails are expensive to generate (full file loaded from MantisBT storage + GD resample). The cache avoids repeating this for each recipient in the same send batch:

1. First recipient: generate JPEG → write to `cache/thumb_{file_id}_{size}.jpg`
2. Subsequent recipients: read cached JPEG from disk (fast)
3. PHP shutdown: all `thumb_*.jpg` files deleted — nothing persists between runs

**Cache location:** `plugins/BetterEmail/cache/`  
**Access control:** `cache/.htaccess` denies all direct HTTP access  
**Git:** `cache/.gitignore` tracks the directory but excludes generated JPEGs

---

## Installation

1. Copy the `BetterEmail/` folder into `plugins/`
2. Log in to MantisBT as administrator → *Manage → Plugins → Install BetterEmail*
3. Ensure SMTP is configured in `config/config_inc.php`:

```php
$g_phpMailer_method = PHPMAILER_METHOD_SMTP;
$g_smtp_host        = 'localhost';
$g_smtp_port        = 1025;
```

---

## File Structure

```
plugins/BetterEmail/
├── BetterEmail.php                 Main plugin — HTML builder, parsers, renderers
├── BetterEmailSender.class.php     Custom EmailSender — PHPMailer setup, sends HTML
├── cache/
│   ├── .gitignore                  Excludes *.jpg from version control
│   └── .htaccess                   Denies direct web access to cache files
└── README.md                       This file
```

---

## Debugging & Log Verification

### Enable logging

Add to `config/config_inc.php`:

```php
$g_log_level       = LOG_EMAIL | LOG_EMAIL_VERBOSE;
$g_log_destination = 'file:/path/to/mantis/logs/mantis.log';
```

Ensure the log file's directory exists and is writable by the web server.

### What to look for

**Single recipient (e.g. 1 subscriber):**
```
MAIL_VERBOSE  email_send_all()             Processing e-mail queue (1 messages)
MAIL_VERBOSE  email_send_all()             Sending message N
MAIL_VERBOSE  make_thumbnail_data_uri()    BetterEmail: thumbnail cached for file_id=X size=200 (NNNN bytes)
MAIL_VERBOSE  email_queue_delete()         message N deleted from queue
MAIL_VERBOSE  BetterEmailPlugin->{closure} BetterEmail: thumbnail cache cleared (N file(s))
```

**Multiple recipients (e.g. 2 subscribers, 2 images):**
```
MAIL_VERBOSE  email_send_all()             Processing e-mail queue (2 messages)
MAIL_VERBOSE  email_send_all()             Sending message 22
MAIL_VERBOSE  make_thumbnail_data_uri()    BetterEmail: thumbnail cached for file_id=23 size=200 (3137 bytes)
MAIL_VERBOSE  make_thumbnail_data_uri()    BetterEmail: thumbnail cached for file_id=24 size=200 (2863 bytes)
MAIL_VERBOSE  email_queue_delete()         message 22 deleted from queue
MAIL_VERBOSE  email_send_all()             Sending message 23
MAIL_VERBOSE  make_thumbnail_data_uri()    BetterEmail: thumbnail cache hit for file_id=23 size=200
MAIL_VERBOSE  make_thumbnail_data_uri()    BetterEmail: thumbnail cache hit for file_id=24 size=200
MAIL_VERBOSE  email_queue_delete()         message 23 deleted from queue
MAIL_VERBOSE  BetterEmailPlugin->{closure} BetterEmail: thumbnail cache cleared (2 file(s))
```

**Things that indicate a problem:**
- No `thumbnail cached` or `thumbnail cache hit` lines → GD (`php-gd`) not installed, or no image attachments in the update
- No `cache cleared` at the end → shutdown function not registering (check PHP error log)
- `thumbnail cached` appearing for every recipient → cache file write failing (check directory permissions on `cache/`)

### Checking GD is available

```bash
php -r "echo function_exists('imagecreatefromstring') ? 'GD OK' : 'GD missing';"
```

---

## EmailReporting Compatibility

The plain-text `AltBody` is structured so that [EmailReporting](https://github.com/mantisbt-plugins/EmailReporting) can parse reply emails correctly:

```
-- Reply above this line to add a comment to issue #N --

[original plain text body below]
```

EmailReplyParser (used by EmailReporting) strips everything from the separator line downward, leaving only the user's reply as the new note body.
