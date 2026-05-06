DRUPALBRIDGE BETA SETUP GUIDE
==============================

1. Install module (see above)

2. Go to: /admin/config/services/drupalbridge

3. Enter your HubSpot App Client ID and Secret
   (from app.hubspot.com/developer)

4. Click "Connect to HubSpot"
   → Approve scopes on HubSpot screen
   → You will be redirected back → Connected!

5. Go to: /admin/config/services/drupalbridge/field-mapping
   → Add mapping: email → Email
   → Add mapping: first_name → First Name
   → Save

6. Go to your webform → Settings → Handlers
   → Add handler → DrupalBridge HubSpot
   → Save

7. Submit a test form on your site

8. Check HubSpot contacts — contact should appear!
