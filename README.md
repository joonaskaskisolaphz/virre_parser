1. Configure settings.yaml
2. Run run_VirreParser.php <business_id> <business_id2> ..
3. Add run_VirreParser.php to crontab

When we want to start tracking a new company, 
we only need to run "php run_VirreParser.php <business_id>" 
once (<business_id> being '1234567-8' for example)

NOTE: run_VirreParser.php can add multiple business ids at once
