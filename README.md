1. Configure settings.yaml
2. Run run_VirreParser.php &lt;business_id&gt; &lt;business_id2&gt; ..
3. Add run_VirreParser.php to crontab

When we want to start tracking a new company, 
we only need to run "php run_VirreParser.php &lt;business_id&gt;" 
once (&lt;business_id&gt; being '1234567-8' for example)

NOTE: run_VirreParser.php can add multiple business ids at once
