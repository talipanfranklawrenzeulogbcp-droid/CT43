# CT4 Merge Manifest
Base: FIRST ZIP — great_solomon_manpower_services_ct4
Process/workflow source: SECOND ZIP — CT44-talipanfranklawrenzeulogbcp-droid-patch-12-workflow-ui-fixed

Preserved from FIRST ZIP:
- Original UI/presentation: style.css, dashboard.php, app.js, logo
- Original one-time feedback workflow: includes/feedback.php
- Existing .env/.env.example
- Existing database/database.sql and database/database.sql.bak as the base database

Replaced/updated from SECOND ZIP:
- Authentication/OTP and shared backend workflow code
- Shared helpers/config/database/runtime
- Health/Safety, Legal/Compliance, Asset/Equipment, Admin/Security services
- Service/API handlers
- Module process implementations
- Deployment files (Dockerfile, docker-entrypoint.sh, health.php)
- Supporting workflow files

Database merge:
- Existing first-ZIP schema/data definitions remain the base.
- Missing second-ZIP workflow tables were appended.
- Asset image columns required by the second ZIP were added with IF NOT EXISTS.
- Existing first-ZIP INSERT/seed data was not replaced.

Feedback compatibility:
- The second ZIP's continuous feedback-thread UI/process was intentionally not copied.
- The first ZIP's one-time feedback/reply workflow remains authoritative.
