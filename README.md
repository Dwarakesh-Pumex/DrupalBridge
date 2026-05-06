# DrupalBridge — Drupal ↔ HubSpot Integration

Professional Drupal module to sync Drupal data with HubSpot CRM using secure OAuth authentication.

---

##  Features

* Real-time Drupal → HubSpot contact sync
* Webform submission integration
* Queue-based retry system for reliability
* OAuth 2.0 secure authentication
* GDPR-compliant contact deletion handling

---

##  Requirements

* Drupal 10 or 11
* PHP 8.1+
* HubSpot account
* Webform module (for form sync)

---

##  Installation

### Step 1 — Download the module

Download from GitHub:

https://github.com/Dwarakesh-Pumex/DrupalBridge/archive/refs/heads/main.zip

---

### Step 2 — Install in Drupal

1. Extract the ZIP
2. Rename folder to:

```
drupalbridge
```

3. Place it inside your Drupal site:

```
/modules/custom/drupalbridge
```

4. Enable the module:

```
drush en drupalbridge -y
drush cr
```

---

## 🔗 Connect to HubSpot (OAuth)

1. Go to:

```
/admin/config/services/drupalbridge
```

2. Enter:

* HubSpot App Client ID
* HubSpot App Client Secret

3. Click:

```
Connect to HubSpot
```

4. Authorize your account in HubSpot

---

## ⚙️ Configure Sync

### Sync Mode

Choose:

* **Real-time** (recommended)
* Queued (cron-based)

---

### Field Mapping

Go to:

```
/admin/config/services/drupalbridge/field-mapping
```

Map Drupal fields to HubSpot properties.

Minimum required:

```
email → Email
```

---

## 🧾 Webform Integration

1. Open your webform
2. Go to:

```
Settings → Emails / Handlers
```

3. Add handler:

```
DrupalBridge HubSpot
```

4. Save

---

##  Testing

Submit a form and verify that the contact appears in HubSpot CRM.

---

##  Security

* Uses OAuth 2.0 (no API keys stored)
* Tokens are securely stored
* Supports automatic token refresh

---

##  Webhooks

Supports HubSpot contact deletion events for GDPR compliance.

---

##  Support

* Documentation: https://drupalbridge.com/docs
* Issues: https://github.com/Dwarakesh-Pumex/DrupalBridge/issues

---

##  Notes

This integration requires both:

* Installing this Drupal module
* Installing the DrupalBridge app from the HubSpot Marketplace

---
