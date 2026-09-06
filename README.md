# KINT-B24 initialization test

Public, isolated test copy for validating native OMX setup and free GitHub
Actions/rulesets. It is not a production deployment.

Only application source and offline tests were copied. Original Git history,
credentials, configuration, databases, correspondence, archives and operational
documents were excluded. No live KINT or Bitrix24 connection is configured.

Run the offline checks with PHP and the curl, pdo_sqlite and mbstring extensions:

```sh
for test in tests/test_*.php; do php "$test" || exit 1; done
```

OMX provides orchestration; existing Spec Kit and Superpowers skills provide
planning, execution and review. GitHub Actions runs the offline tests. Repository
rulesets enforce pull requests and successful checks on the default branch.
No additional orchestration service or custom executable hook is used.
