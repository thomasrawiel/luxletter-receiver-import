Backend module extracted from an internal extension


Orignal authors: Andreas Nedbal, Christine Zoglmeier, Oliver Eglseder


# Changelog:
## [v1.1] – 2025-11-24

**Enhancements / Bugfixes**
- Refactored `indexAction()` to fully support partial imports and display results reliably.
- Added `importAttempted` flag to always show import results, including success/failure counts.
- Added row-level validation:
    - Email format (`GeneralUtility::validEmail`)
    - Group title empty check
    - Group title max length based on `TCA['fe_groups']['columns']['title']['config']['max']`
- Row-level errors are tracked in `$rowErrors` for table display in backend.
- Implemented caching for:
    - Existing frontend users per PID (`$existingUsersCache`)
    - Frontend groups per PID (`$groupsCache`)
- Updated `subscribeFrontendUser()` to:
    - Update groups for existing users (including disabled users) without changing other fields.
    - Return `'insert' | 'update' | 'skip'` for counting purposes.
    - Update cache dynamically to avoid repeated DB queries.
- Preloaded existing users with `preloadExistingUsers()` for performance improvement.
- Ensured passwords are generated securely and **never communicated externally**.
- Form-level argument validation separated from row-level errors.
- Use `Doctrine\DBAL\QueryBuilder` with proper restriction handling (`DeletedRestriction`)
- Optimized XLSX import loop for large files (1–2k rows).
- Removed unnecessary `0` group values when updating usergroups.
- Cleaned template integration to display:
    - Counts (inserted, updated, skipped)
    - Row-level error table
    - Form always below results for repeated imports.
