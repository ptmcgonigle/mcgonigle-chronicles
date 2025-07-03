# Tailwind UI Backend Skin

![Tailwind_UI_Plugin](https://user-images.githubusercontent.com/7253840/176566244-ff859f12-77a5-465e-9462-6380a47652a6.png)

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/wintercms/wn-tailwindui-plugin/blob/main/LICENSE)

[Tailwind UI](https://tailwindui.com/) is a Tailwind CSS component library designed by the authors of [Tailwind CSS](https://tailwindcss.com/). This is a [Winter CMS](https://wintercms.com) plugin that provides a custom, TailwindUI-based skin for the backend.

Supports:
- Multiple authentication page layouts (Simple, Left Sidebar)
- Backend Menu location customization (Top, Side)
- Backend Menu Icon location customization (Above, Beside, Hidden (Text Only), Only (No Text))
- Background image for login page
- Dark mode

## Installation

This plugin is available for installation via [Composer](http://getcomposer.org/).

```bash
composer require winter/wn-tailwindui-plugin
```

After installing the plugin you will need to run the migrations and (if you are using a [public folder](https://wintercms.com/docs/develop/docs/setup/configuration#using-a-public-folder)) [republish your public directory](https://wintercms.com/docs/develop/docs/console/setup-maintenance#mirror-public-files).

```bash
php artisan migrate
```

## Screenshots

| Before | After |
|---|---|
| ![stock-winter](https://github.com/wintercms/wn-tailwindui-plugin/assets/7253840/096dffdc-6c21-4e8a-ae3d-5d09fa1dd251) | ![tailwind-ui-light](https://github.com/wintercms/wn-tailwindui-plugin/assets/7253840/fad97b4b-8c29-4615-bdc3-b04886b2e467) |

### Dark Mode!

Dark mode and user preferences are also supported.

![tailwind-ui-dark](https://github.com/wintercms/wn-tailwindui-plugin/assets/7253840/b6c866d5-f64a-4788-88f7-61364c7599b4)

![tailwind-preferences](https://github.com/wintercms/wn-tailwindui-plugin/assets/7253840/6c21966a-07d3-4427-a6b6-2902c8c38527)

## Getting started

Use composer to install the plugin:

```bash
composer require winter/wn-tailwindui-plugin
```

Then, run the migrations to ensure the plugin is enabled:

```bash
php artisan migrate
```

## Configuration

Configuration for this plugin is handled through a [configuration file](https://wintercms.com/docs/plugin/settings#file-configuration). In order to modify the configuration values and get started you can either add the values to your `.env` environment file or copy the `plugins/winter/tailwindui/config/config.php` file to `config/winter/tailwindui/config.php` and make your changes there.

Environment File Supported Values:
- `TAILWIND_SHOW_BREAKPOINT_DEBUGGER=false`

## Using Tailwind in other Plugins

The following steps should be taken in order to ensure the best compatibility between plugins when using Tailwind with other plugins in the Backend:

- Use [Laravel Mix](https://wintercms.com/docs/v1.2/docs/console/asset-compilation) to handle compiling your plugin's Tailwind styles
- In your `tailwind.config.js` file, take the following actions:
  - Extend the Winter.TailwindUI plugin's configuration rather than the default Tailwind configuration (ex: `const config = require('../../winter/tailwindui/tailwind.config.js');`).
  - Ensure that the [Preflight Tailwind plugin](https://tailwindcss.com/docs/preflight#disabling-preflight) is disabled (ex: `config.corePlugins = {preflight: false};`).
  - Set `config.content` to include only your plugin's paths (ex: `config.content = ['./formwidgets/**/*.{vue,php,htm}', './components/**/*.{php,htm}', './assets/src/js/**/*.{js,vue}'];`).
- In your `package.json` file, include [postcss-prefixwrap](https://www.npmjs.com/package/postcss-prefixwrap) to wrap your plugin's generated styles in a plugin-specific class to prevent overriding the styles elsewhere in the backend (ex. `"postcss-prefixwrap": "~1.29.x",`).
- In your `winter.mix.js` file, use postcss-prefixwrap when compiling the Tailwind styles (ex. `mix.postCss('assets/src/css/example.css', 'assets/dist/css/example.css', [..., require('postcss-prefixwrap')('.plugin-authorname-pluginname'), ...])`).

### Example `tailwind.config.js`:

```js
// Extend the base tailwind config to avoid conflicts
const config = require('../../winter/tailwindui/tailwind.config.js');

config.content = [
    './formwidgets/**/*.{vue,php,htm}',
    './components/**/*.{php,htm}',
    './assets/src/js/**/*.{js,vue}',
];

config.corePlugins = {
    preflight: false,
};

module.exports = config;
```

### Example `winter.mix.js`:

```js
const mix = require('laravel-mix');

mix.setPublicPath(__dirname)

    // Compile Tailwind
    .postCss(
        'assets/src/css/myplugin.css',
        'assets/dist/css/myplugin.css',
        [
            require('postcss-import'),
            require('tailwindcss/nesting'),
            require('tailwindcss'),
            require('autoprefixer'),
            require('postcss-prefixwrap')('.myauthor-pluginname', {
                // Don't prefix wrap modals because we can't put the wrapping class on a high enough parent element to apply the styles
                ignoredSelectors: ['.modal'],
            })
        ]
    );
```

### Example `package.json`:

```json
{
    "name": "myauthor-pluginname",
    "version": "0.0.1",
    "private": true,
    "version": "1.0.0",
    "devDependencies": {
        "postcss": "~8.4.x",
        "postcss-prefixwrap": "~1.29.x",
        "postcss-import": "~14.1.x",
        "tailwindcss": "~3.0.x",
        "@tailwindcss/typography": "0.5.8"
    }
}
```

### Future Ideas

It would be ideal if it was also possible for other plugins to detect the classes that have already been generated by this plugin and prune them from their compiled styles. Pull Requests welcome to add that ability in the future if anyone has the time / motivation to do so.

## Credits
This plugin was originally written by Joseph Blythe & Luke Towers for [Spatial Media](https://spatialmedia.io).

It has since been modified and re-released under the Winter namespace as a first party plugin for Winter CMS maintained by the Winter CMS team.

If you would like to contribute to this plugin's development, please feel free to submit issues or pull requests to the plugin's repository here: https://github.com/wintercms/wn-tailwindui-plugin

If you would like to support Winter CMS, please visit [WinterCMS.com](https://wintercms.com/support)
