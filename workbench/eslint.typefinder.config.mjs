// Lints the generated reference output (see GenerateReferenceOutputTest).
// `tsc` catches output that does not compile; this catches output that
// compiles but that a consumer's linter would reject, such as one module
// imported on several lines (#214) or a redundant `unknown | null`.
import eslint from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config({
    files: ['**/resources/js/typefinder/**/*.ts', '**/resources/js/typefinder-variant/**/*.ts'],
    extends: [
        eslint.configs.recommended,
        ...tseslint.configs.strictTypeChecked,
        ...tseslint.configs.stylisticTypeChecked,
    ],
    languageOptions: {
        parserOptions: {
            project: ['./tsconfig.typefinder.json'],
            tsconfigRootDir: import.meta.dirname,
        },
    },
    rules: {
        'no-duplicate-imports': 'error',
        // Typefinder emits `type` aliases on purpose: they cannot be merged
        // with a declaration elsewhere, so the generated shape is the shape.
        '@typescript-eslint/consistent-type-definitions': 'off',
    },
});
