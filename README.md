# DrupalBridge — Drupal HubSpot Integration

Professional Drupal-HubSpot CRM integration module.

## Requirements

- Drupal 10 or 11
- PHP 8.1+
- HubSpot account with Private App token
- Webform module (for webform sync)

## Installation

### Step 1 — Install the module
```bash
composer require pumex/drupalbridge
drush en drupalbridge -y
drush updb -y
drush cr
```

### Step 2 — Configure API token

Go to: `/admin/config/services/drupalbridge`

- Enter your HubSpot Private App token
- Enter your HubSpot Portal ID
- Click **Test Connection**
- Select sync mode (Real-time recommended)
- Save configuration

### Step 3 — Configure field mapping

Go to: `/admin/config/services/drupalbridge/field-mapping`

- Add mappings from Drupal fields to HubSpot properties
- At minimum map: email → Email

### Step 4 — Add webform handler

Go to your webform → Settings → Emails/Handlers

- Click **Add handler**
- Select **DrupalBridge HubSpot**
- Save

### Step 5 — Test

Submit your form and check HubSpot contacts.

## HubSpot Private App Scopes Required

- `crm.objects.contacts.write`
- `crm.objects.contacts.read`
- `forms`

## Support

- Documentation: https://drupalbridge.com/docs
- Issues: https://github.com/pumex/drupalbridge/issues
