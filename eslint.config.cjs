module.exports = [
  {
    files: ["**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "script",
      globals: {
        console: "readonly",
        PPA_AJAX: "readonly",
        ajaxurl: "readonly",
        document: "readonly",
        jQuery: "readonly",
        $: "readonly",
        window: "readonly",
        wp: "readonly"
      }
    },
    rules: {
      "no-undef": "off",
      "no-unused-vars": [
        "warn",
        { "args": "none", "ignoreRestSiblings": true, "varsIgnorePattern": "^_" }
      ]
    }
  }
];
