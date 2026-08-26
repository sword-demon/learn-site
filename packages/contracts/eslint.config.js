import js from "@eslint/js";
import tseslint from "@typescript-eslint/eslint-plugin";
import tsparser from "@typescript-eslint/parser";

export default [
  js.configs.recommended,
  {
    files: ["src/**/*.ts"],
    languageOptions: {
      parser: tsparser,
      parserOptions: {
        ecmaVersion: 2022,
        sourceType: "module",
      },
    },
    plugins: { "@typescript-eslint": tseslint },
    rules: {
      "no-unused-vars": "off",
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_" },
      ],
      // Trust boundary is enforced at parse-time via Zod; explicit any is a code-review trap.
      "@typescript-eslint/no-explicit-any": "error",
      // Schemas often ship paired with `type X = z.infer<typeof X>` in the same file —
      // TypeScript treats const/type as distinct bindings; this rule mis-fires.
      "no-redeclare": "off",
    },
  },
  {
    ignores: [
      "dist/**",
      "build/**",
      "node_modules/**",
      "coverage/**",
      "**/*.min.js",
      "src/**/*.test.ts",
    ],
  },
];
