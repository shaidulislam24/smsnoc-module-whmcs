=== SMS NOC Gateway for WHMCS ===
Version: 1.0.0
Author: SMS NOC (https://smsnoc.com)
Requires: WHMCS 7.0+, PHP 7.4+

== Description ==
Complete multi-channel messaging addon for WHMCS — SMS, Voice (with TTS), WhatsApp & Email.
55+ automated event hooks, OTP authentication, client search/bulk send, advanced rate limiting.

== Features ==
- 55+ automated hooks (Invoice, Ticket, Service, Domain, Client, Order)
- Admin/Customer targeting with color-coded badges
- Voice Priority Chain: Template File → Default File → TTS → SMS Fallback
- Text-to-Speech (English, Bangla, Hindi) with male/female voices
- Per-template channel override & voice file URL
- OTP Login, Registration, Forgot Password (local OTP engine)
- 3-tier rate limiting: Per-Phone Hourly/Daily, Per-IP Hourly
- Resend cooldown & max wrong attempts
- Client search/filter/multi-select bulk send
- Fallback channel (auto-retry if primary fails)
- 30-second dedup lock prevents duplicate messages
- Activity log with channel/status filters
- Modern dark dashboard with tab navigation
- SMS character counter (GSM-7 / Unicode detection)

== Installation ==
1. Upload the `smsnoc` folder to `/modules/addons/smsnoc/`
2. Go to Setup → Addon Modules → Activate "SMS NOC Gateway"
3. Open the module dashboard and enter your API Key from smsnoc.com
4. Configure channels, hooks, and templates

== API ==
Hardcoded endpoint: https://smsnoc.com/api/v1

== Files ==
- smsnoc.php       — Main module (dashboard, settings, tabs)
- hooks.php        — 55+ WHMCS event hooks
- otp_handler.php  — OTP send/verify/login/reset endpoint
- lib/SMSNOC_API.php — API client library

== Changelog ==
= 1.0.0 =
* Initial stable release
* 55+ hooks with per-event toggles
* Voice TTS auto-conversion with multi-field response support
* Local OTP engine with 3-tier rate limiting
* Client search/filter/multi-select bulk send
* Per-template channel override & voice file
* Fallback channel with auto-retry
