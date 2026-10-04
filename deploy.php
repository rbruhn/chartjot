<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'deploy/custom.php';

/*
 * CHART JOT CI/CD DEPLOYMENT CONFIGURATION
 *
 * USAGE:
 * - Automatic deployment via GitHub Actions (push to main)
 * - Manual deployment: dep deploy production
 *
 * HOW IT WORKS:
 * 1. GitHub Actions builds the Vite frontend assets and uploads them as an artifact
 * 2. Deployer clones the repo, installs Composer deps, then downloads & extracts
 *    the pre-built assets (no server-side Node/npm required)
 * 3. Queue-safe deployment (Horizon is terminated and supervisor restarts it)
 * 4. Automatic cleanup (node_modules removed, if present)
 */

// Deployment Configuration
// Private repository: cloned over SSH with the read-only REPO_DEPLOY_KEY, which GitHub Actions loads into
// its SSH agent and Deployer forwards to the server (forward_agent below). A manual `dep deploy` needs an
// agent holding a key with read access to the repository.
set('repository', 'git@github.com:rbruhn/chartjot.git');
set('base_path', '/var/www');
set('remote_user', 'ubuntu');
set('php_fpm_version', '8.5');
set('ssh_multiplexing', true);
set('writable_mode', 'acl');       // PHP-FPM runs as www-data; grant it write access via ACL
set('writable_recursive', true);   // Apply recursively so pre-existing storage/ content is covered too
set('keep_releases', 3);  // Keep only 3 recent releases

// Use sudo for cleanup to prevent "Directory not empty" or permission errors
set('cleanup_use_sudo', true);

// Shared Files (persisted across deployments)
set('shared_files', [
    '.env',                        // Environment configuration
]);

// Shared Directories (persisted across deployments)
set('shared_dirs', [
    'storage',          // Laravel storage (logs, cache, uploads)
]);

// Files/Directories to Remove After Deployment
set('clear_paths', [
    'node_modules',     // Remove if present (shouldn't be, assets are built in CI)
]);

// Phase-specific DB update operation for this branch.
set('update_queries_operation', '');

// Server Configuration
// Production: main branch → /var/www/chartjot
host('production')
    ->set('hostname', '40.160.146.238')
    ->set('deploy_path', '{{base_path}}/chartjot')
    ->setForwardAgent(true)
    ->set('branch', 'main')
    ->set('environment', 'production');

/*
 * DEPLOYMENT TASK SEQUENCE
 *
 * This sequence eliminates server-side asset building by using the CI artifact.
 * Each task is executed in order with proper error handling.
 */
desc('Deploys your project using CI/CD artifacts');
task('deploy', [
    // Phase 1: Preparation
    'deploy:prepare',           // Create release directory and setup structure

    // Phase 2: Dependencies & Assets
    'deploy:vendors',          // Install PHP Composer dependencies safely
    'deploy:ci-artifacts',     // Download & extract pre-built Vite assets from GitHub Actions

    // Phase 3: Laravel Setup
    'artisan:storage:link',    // Create symbolic link for storage directory
    'artisan:package:discover', // Run package discovery after environment is ready (SAFE: after .env is linked)

    // Phase 4: Database
    'artisan:migrate',         // Run database migrations

    // Phase 5: Cache Optimization
    'artisan:optimize:clear',  // Clear all Laravel caches
    'artisan:cache:clear',     // Clear application cache
    'artisan:config:cache',    // Cache configuration files
    'artisan:route:cache',     // Cache route definitions
    'artisan:view:cache',      // Cache Blade templates
    'artisan:event:cache',     // Cache event listeners
    'artisan:optimize',        // Run Laravel optimization

    // Phase 6: Queue Worker
    'artisan:horizon:terminate', // Gracefully stop Horizon; supervisor (autorestart) brings it back up on the new code

    // Phase 7: Finalization
    'truncate:logs',           // Truncate shared logs before publish
    'deploy:clear_paths',      // Remove unnecessary files/directories
    'deploy:publish',          // <--- SYMLINK SWITCHES HERE
]);

// Hooks
after('deploy:failed', 'deploy:unlock');
