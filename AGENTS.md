# Repository Guidelines

## Project Structure & Modules
- Laravel backend lives in `app`, configuration in `config`, and HTTP routes in `routes`.
- Blade views and frontend assets are in `resources`; public entry points and built assets are in `public`.
- Tests are under `tests` (PHPUnit/Pest) and database seeders/migrations are in `database`.
- CI workflows are in `.github/workflows`.

## Build, Test, and Development
- `php artisan serve` — Run the Laravel app locally.
- `npm install` / `npm run dev` — Install JS dependencies and start Vite dev server.
- `npm run build` — Build production frontend assets.
- `php artisan migrate --seed` — Apply migrations and seed demo data (where applicable).
- `php artisan test` — Run the backend test suite.

## Coding Style & Naming
- Follow Laravel conventions: PSR-12 for PHP, 4-space indentation, and type hints where reasonable.
- Controllers, models, actions, and Livewire/Filament classes use `StudlyCase`; methods and variables use `camelCase`.
- Blade view files use `kebab-case.blade.php` inside feature-specific folders in `resources/views`.
- Keep frontend JS/TS/SCSS consistent with existing patterns; prefer small, focused components.

## Testing Guidelines
- Use PHPUnit/Pest tests in `tests/Feature` and `tests/Unit`; mirror namespaces to `app`.
- Name tests descriptively (e.g., `UserCanUpdateProfileTest.php`) and keep one behavior per test method.
- Run `php artisan test` locally before opening a pull request.

## Commit & Pull Request Guidelines
- Write clear, imperative commit messages (e.g., `Add user profile form`, `Fix login validation`).
- For pull requests, include a concise description, screenshots for UI changes, and any relevant issue links.
- Note any migrations, seeding steps, or breaking changes in the PR description.
