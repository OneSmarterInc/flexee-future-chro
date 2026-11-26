# The Future CHRO: verifying a release

For whoever releases. After each release, run this on the server and send Claude the whole output:

```
php artisan ashby:verify --database
```

Every line prints what was expected beside what came out.

| Group | What it checks |
|---|---|
| The engine | Each reference team reproduces every fixture value in all three futures, and its judgment |
| The lessons | The builder has the best worst case; cutting pays first and costs later; the verification wall arrives after 2031 in the rapid future; letting Ruth go costs more than keeping her |
| Words | No banned word on any participant screen; the manuals agree with the product; the What changed log is current |
| The pipeline (rolled back) | A throwaway class of the four reference teams is opened, played and closed on this database, three teams confirming and the fourth confirmed by the close. Every replay judgment must match the fixtures. Then everything is rolled back, and the check confirms nothing was left behind |
| The site (for information) | Whether AI, outgoing mail and the reporting connector are on |

The last line says "All N checks passed", or how many failed. A failure prints the expected and actual values. Send it to Claude as it is.

Two more checks after a release:

```
php artisan ashby:manual-log --check   # the manuals agree with the product
php artisan ashby:ai-check             # only when ANTHROPIC_API_KEY is set
```
