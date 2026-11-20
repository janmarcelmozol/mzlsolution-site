# MZL Solution — website

Website for **mzlsolution.com** (IT Field Services & Infrastructure, Florida), hosted on HostGator.

## Structure
- `public/` — files served at mzlsolution.com (copied to `public_html`).
  - `index.html` — the site.
  - `send.php` — contact form handler, emails requests to mzl@mzlsolution.com.
  - `chat.php` — AI chat assistant backend (Claude API).
  - `thanks.html` — page shown after the form is sent.
- `server/` — PHP dependencies (Anthropic SDK). Stays outside `public_html`.

## Deployment
A cPanel cron job pulls this repo every 5 minutes and copies `public/` to `public_html`:

```
cd /home1/janmar94/repositories/mzlsolution-site && PATH=/usr/local/cpanel/3rdparty/bin:$PATH git pull -q origin main && cp -a public/. /home1/janmar94/public_html/ >/dev/null 2>&1
```

## Chat assistant setup
Create `/home1/janmar94/mzl-chat-config.php` (outside `public_html`) with:

```php
<?php return ['anthropic_api_key' => 'YOUR_KEY_HERE'];
```

Limits in `chat.php`: 20 messages per conversation, 40 requests per visitor per hour, 400 per day for the whole site.
