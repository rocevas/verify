# Email verifier

Test serive
sail artisan test --filter=EmailVerificationServiceTest

Test email
sail artisan email:test srocevas@gmail.com

sail php artisan email:verify srocevas@gmail.com --json


# Analizuoti email iš CSV failo
sail artisan email:analyze-scoring test-email-scoring-analysis.csv

# Su custom CSV failu
sail artisan email:analyze-scoring custom-emails.csv

# Be CSV output (tik terminal output)
sail artisan email:analyze-scoring test-email-scoring-analysis.csv --output=terminal

