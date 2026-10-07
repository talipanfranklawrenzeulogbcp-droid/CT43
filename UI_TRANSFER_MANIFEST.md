# UI Transfer Manifest

Base: first ZIP (`CT4_merged_first_UI_feedback_second_processes_env_updated(2).zip`)

Transferred from second ZIP:
- Terms & Conditions login UI markup (`auth/login.php`) only.
- Legal Compliance recruitment-agency document-requirements/sample-document UI markup (`modules/legal_compliance/index.php`) only.
- CSS selectors required to render those two UI components, including their responsive document-list behavior.

Preserved from first ZIP:
- OTP/Gmail logic and authentication workflow.
- Legal Compliance service/backend logic.
- Database schema and data.
- Existing routes, feedback workflow, and other module behavior.
- All unrelated UI/styles.

No database records from the second ZIP were copied.
