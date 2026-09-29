<?php

namespace Deployer;

function withUmask(string $command): string
{
    return "umask 002 && $command";
}

/*
 * =============================================================================
 * COMPOSER DEPENDENCY MANAGEMENT - SAFE INSTALLATION
 * =============================================================================
 */

desc('Install Composer dependencies safely (with --no-scripts to prevent database connection issues)');
task('deploy:vendors', function () {
    if (! test('[ -f {{release_path}}/composer.json ]')) {
        return;
    }

    run(withUmask('cd {{release_path}} && {{bin/composer}} clear-cache'));

    // Install without dev dependencies for production
    run(withUmask('cd {{release_path}} && {{bin/composer}} install --prefer-dist --no-cache --no-progress --no-interaction --no-dev --optimize-autoloader --no-scripts'));
    writeln('✅ Composer dependencies installed safely (production environment - without dev packages)');
});

desc('Run Laravel package discovery after environment is ready');
task('artisan:package:discover', function () {
    cd('{{release_or_current_path}}');
    run(withUmask('php artisan package:discover --ansi'));
    writeln('✅ Laravel package discovery completed');
});

/*
 * =============================================================================
 * CUSTOM LARAVEL ARTISAN TASKS
 * =============================================================================
 */

desc('Running custom database update queries');
task('artisan:app:update-queries-command', function () {
    cd('{{release_or_current_path}}');

    $operation = trim((string) get('update_queries_operation', ''));

    // Keep task available for ad-hoc runs; only execute when operation is provided.
    if ($operation === '') {
        writeln('⚠️  Skipping app:update-queries-command: update_queries_operation is not configured.');
        writeln('   Set it in deploy.php or pass it at runtime for manual task execution.');

        return;
    }

    run(withUmask("php artisan app:update-queries {$operation} --force"));
    writeln("✅ app:update-queries completed for operation: {$operation}");
});


/*
 * =============================================================================
 * FILE SYSTEM & LOG MANAGEMENT
 * =============================================================================
 */

desc('Truncate shared Laravel log files');
task('truncate:logs', function () {
    run(withUmask("find {{deploy_path}}/shared/storage/logs -type f -name '*.log' -exec truncate -s 0 {} +"));
});

/*
 * =============================================================================
 * CI/CD ARTIFACT DEPLOYMENT - CORE OF OPTION 1 IMPLEMENTATION
 * =============================================================================
 */

desc('Download and extract pre-built Vite assets from GitHub Actions');
task('deploy:ci-artifacts', function () {
    // Environment variables automatically provided by GitHub Actions workflow
    $githubActions = ($_ENV['GITHUB_ACTIONS'] ?? getenv('GITHUB_ACTIONS') ?? '') === 'true';

    $githubToken = $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?? '';
    $githubSha = $_ENV['GITHUB_SHA'] ?? getenv('GITHUB_SHA') ?? '';
    $githubRepo = $_ENV['GITHUB_REPO'] ?? getenv('GITHUB_REPO') ?? 'rbruhn/chartjot';

    // If not running in GitHub Actions, skip this task (manual deploy path)
    if (! $githubActions) {
        writeln('⚠️  Skipping deploy:ci-artifacts (not running in GitHub Actions).');
        writeln('    Tip: Use GitHub Actions for artifact-based deploys, or build assets locally instead.');

        return;
    }

    // Validate required environment variables
    if (empty($githubToken) || empty($githubSha)) {
        // Provide more helpful error message with debugging information
        $envVars = array_keys($_ENV);
        $relevantEnvVars = array_filter($envVars, function ($key) {
            return strpos(strtoupper($key), 'GITHUB') !== false;
        });

        $errorMsg = "GITHUB_TOKEN and GITHUB_SHA environment variables are required.\n";
        $errorMsg .= 'Available GitHub-related env vars: '.implode(', ', $relevantEnvVars)."\n";
        $errorMsg .= 'All env vars count: '.count($envVars);

        throw new \Exception($errorMsg);
    }

    // Artifact naming convention: chartjot-{git-sha}
    $artifactName = "chartjot-{$githubSha}";
    writeln("Downloading CI artifact: {$artifactName}");

    // Step 1: Get artifact download URL from GitHub API
    $apiUrl = "https://api.github.com/repos/{$githubRepo}/actions/artifacts";
    $response = runLocally("curl -H 'Authorization: Bearer {$githubToken}' -H 'Accept: application/vnd.github.v3+json' '{$apiUrl}?name={$artifactName}&per_page=1'");
    $artifacts = json_decode($response, true);

    // Validate artifact exists
    if (empty($artifacts['artifacts'])) {
        throw new \Exception("No CI artifact found with name: {$artifactName}");
    }

    $downloadUrl = $artifacts['artifacts'][0]['archive_download_url'];
    cd('{{release_or_current_path}}');

    // Step 2: Download and extract the CI-built assets (public/build/**, nothing else,
    // so this just unpacks flat into the release — no nesting to flatten).
    run(withUmask("curl -L -H 'Authorization: Bearer {$githubToken}' -H 'Accept: application/vnd.github.v3+json' '{$downloadUrl}' -o artifact.zip"));
    run(withUmask('unzip -o -q artifact.zip'));
    run(withUmask('rm -f artifact.zip'));

    writeln('✅ CI assets deployed successfully - No server-side building required!');
});
