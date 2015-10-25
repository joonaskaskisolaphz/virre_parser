1. Configure settings.yaml
2. Run commands below:
```
composer install
composer dump-autoload
php run_VirreParser.php 1234567-8
```
When we want to start tracking a new company, 
we only need to run "php run_VirreParser.php &lt;business_id&gt;" 
once (&lt;business_id&gt; being '1234567-8' for example)

NOTE: run_VirreParser.php can add multiple business ids at once
