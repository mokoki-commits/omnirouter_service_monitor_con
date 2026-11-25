# OmniRoute Health Monitoring System

## Overview
This project contains a robust PHP-based health monitoring and automated recovery system for the `omniroute` service.

## Core Components
- **Health Check Engine:** `/home/testai26/.cline/data/workspaces/chat/omniroute_monitor/monitor_omniroute.php`
  - Performs deep diagnostics (Systemd status, MainPID, /proc analysis, Port listening, API probing, Log/DB freshness).
  - Handles auto-recovery (restart via `systemctl --user`).
  - Implements safety features: `flock` (single instance), restart loop prevention (max 3/hr), and grace periods.

## Operational Data
- **Status Report:** `/home/testai26/hermes-agent/omniroute_health_report.txt`
- **Internal Logs & State:** `/home/testai26/.cache/omniroute-health/`
- **Execution Log:** `/home/testai26/.cline/data/workspaces/chat/omniroute_monitor/monitor.log`

## Maintenance & Monitoring
- **Verify Automation:** Check `crontab -l` to ensure the minute-interval job exists.
- **Check Logs:** If the service is behaving unexpectedly, inspect `monitor.log` for script execution issues.
- **Check Reports:** Review `omniroute_health_report.txt` for the latest health status and diagnostic details.

## Setup Requirements
- PHP installed on the system.
- `systemctl --user` properly configured for the `testai26` user.
- Access to read `/proc` and write to the monitoring directories.
