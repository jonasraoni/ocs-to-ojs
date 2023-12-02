# OCS (Open Conference Systems) to OJS (Open Journal Systems) Migration Tool

[![OJS compatibility](https://img.shields.io/badge/ojs-3.2_up_to_3.4-brightgreen)](https://github.com/pkp/ojs/tree/stable-3_4_0)
[![GitHub release](https://img.shields.io/github/v/release/jonasraoni/ocs-to-ojs?include_prereleases&label=latest%20release)](https://github.com/jonasraoni/ocs-to-ojs/releases)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/jonasraoni/ocs-to-ojs)
[![License type](https://img.shields.io/github/license/jonasraoni/ocs-to-ojs)](https://github.com/jonasraoni/ocs-to-ojs/blob/main/LICENSE)
[![Number of downloads](https://img.shields.io/github/downloads/jonasraoni/ocs-to-ojs/total)](https://github.com/jonasraoni/ocs-to-ojs/releases)
[![Commit activity per year](https://img.shields.io/github/commit-activity/y/jonasraoni/ocs-to-ojs)](https://github.com/jonasraoni/ocs-to-ojs/graphs/code-frequency)
[![Contributors](https://img.shields.io/github/contributors-anon/jonasraoni/ocs-to-ojs)](https://github.com/jonasraoni/ocs-to-ojs/graphs/contributors)

This migration tool is based on exporting **published** papers from an OCS 2.3.6 installation into OJS through the generation of specialized XML files compatible with its `Native XML` plugin.

It assumes that you have an older server, probably running PHP 5.6, hosting the OCS installation, and a new one, running PHP +7.x, hosting the OJS installation.

For this reason the process was broken in two parts, [export](#2-export) (runs on the OCS server) and [import](#3-import) (runs on the OJS server).

**TL;DR:** Run the `export.php` on the OCS server > Move the generated data to somewhere accessible by the OJS server > Adjust anything you want > Run the `import.php` on the OJS server.


## Variables

We'll be using these variables across the instructions:

- **`${OCS_TO_OJS_PATH}`:** Directory where this repository is located.
- **`${OCS_PATH}`:** Directory where the OCS installation is located.
- **`${OJS_PATH}`:** Directory where the OJS installation is located.
- **`${DATA_PATH}`:** Directory where the exported OCS data will be placed.


## 1. Preparation

- For safety reasons, always prepare a backup of your application files and database before proceeding with such migrations in order to recover from unexpected failures.
- Ensure you're using the [latest OCS release (2.3.6)](https://github.com/pkp/ocs/tree/ocs-2_3_6-0). The process **might** work with previous versions, but it hasn't been tested.
- Ensure both the OCS and the OJS installation are functional, with all user data and files, such as the public folder, in place.
  - In case your OCS installation is being restored from a backup, notice that OCS requires at maximum PHP 5.6, also it's better to go with a not so high MySQL version (MySQL 5.6 is a good fit), if you don't have such environment available, Docker might be a good way to get it working without major efforts.


## 2. Export

> This step isn't supposed to cause side-effects to your OCS installation, anyway, prepare a backup for safety reasons.

⚠ This script makes use of the OCS code internally, so ensure you're running it using a PHP client compatible with your OCS (probably PHP 5.6).

This process will extract the papers and some extra metadata from the OCS installation into the `${DATA_PATH}` folder. The generated files will be used as input for the [import](#3-import) process.

At the end of the process you should have these files:
- `${DATA_PATH}/metadata.json`: A JSON file holding OCS metadata for the conferences, scheduled conferences and tracks, which will be respectively mapped into OJS as journals, issues and sections.
- `${DATA_PATH}/papers/*.xml`: This folder will have a XML file for each exported paper, using the following naming pattern: `{$conferenceId}-{$scheduledConferenceId}-{$trackId}-{$paperId}.xml`

You can start by running `php ${OCS_TO_OJS_PATH}/export.php`, it will display a short usage description. Below is a complete reference for the required and optional arguments:
- **`-i ${OCS_PATH}`:** The path to the OCS installation.
- **`-o ${DATA_PATH}`:** The path where the script will store the deliverables.
- **`-t TARGET_OJS_VERSION`:** Indicates the target OJS version. The script generates custom data based on the OJS version, at this moment you can specify one of `stable-3_2_1`, `stable-3_3_0` and `stable-3_4_0`.
- **`-f` (optional):** Force flag. The script will fail if your OCS isn't at the version `2.3.6.0` or if the `${DATA_PATH}` folder already exists, running with `-f` will ignore both warnings.
- **`-s` (optional):** If this flag is specified supplementary files will be imported as public galleys, otherwise they will be imported as private submission files.
- **`conferencePath1 conferencePath2 conferencePathN` (optional):** The script will export **all** conferences by default, unless you specify at the end of the command the conference paths that you need.

Setup all arguments and execute the `${OCS_TO_OJS_PATH}/export.php` script, at the end you should see the message `Export finished with success`. Proceed to the [import](#3-import) step.


## 3. Import

> This process can leave your installation in a broken state in case something goes wrong, backup your files and database before proceeding. If you're able to prepare a sandbox installation, that would be the best place to test the import.

⚠ This script makes use of the OJS code internally, so ensure you're running it using a PHP client compatible with your OJS.

The process depends on the `Native XML`/`Native Import Export Plugin`, which comes bundled with OJS. When the plugin fails to import an article, errors might be silenced by OJS. Ensure you've enabled the setting `[debug].display_errors` at your `${OJS_PATH}/config.inc.php` before running the import script, this will give you better clues to understand what happened.

> The OJS installation doesn't need to be clean/empty, data from OCS might be inserted as additional content.

The script first attempts to match conferences, scheduled conferences and tracks to existing resources at the OJS installation.

In case something cannot be found/mapped, it will stop and ask you whether you want to:
- Map them manually (using the metadata file)
- Remove/ignore the mapping step (e.g. if you decide to remove a conference, all of its related papers will not be imported as well)
- Let the script create the missing data for you (see the `-f` argument below).

Once the mapping is done, the script will pass through every file at the `${DATA_PATH}/papers` and import them.

If you want to skip specific papers from the import, you can remove their respective files from the import folder (you can identify it by ID, the format was explained at the export step).

If you want to ignore a specific conference, scheduled conference or track, remove it from the file `metadata.json`, the import will process only properly mapped data.

You can start by running `php ${OCS_TO_OJS_PATH}/import.php`, it will display a short usage description. Below is a complete reference for the required and optional arguments:
- **`-i ${DATA_PATH}`:** The path where the export script left the OCS data.
- **`-o ${OJS_PATH}`:** The path to the OJS installation.
- **`-u IMPORT_USERNAME`:** The username of an OJS user which will be defined as the creator/owner of the imported papers/articles.
- **`-a ADMIN_USERNAME`:** The username of an OJS user which will be used while creating (if needed, see the `-f` argument below) the journals.
- **`-p PHP_EXECUTABLE_PATH` (optional):** Path to the PHP executable (in case you have multiple PHP executables on your server), it will be used to run the `${OJS_PATH}/tools/importExport.php` on OJS.
- **`-f LEVEL` (optional):** The script will do several checks before doing the import, the levels below will provide an interactive way to progress and decide how to solve each problem. The argument accepts values from `0` until `4`, where:
  - **`<= 0`:** No effect.
  - **`\>= 1`:** The script will ignore if your OJS version doesn't match the one you've specified at the [export](#2-export).
  - **`\>= 2`:** The script will ignore if your OJS installation is missing required locales.
  - **`\>= 3`:** The script will create missing journals.
  - **`\>= 4`:** The script will create missing issues.
  - **`\>= 5`:** The script will create missing sections.
  - **`\>= 6`:** The script will not check if the OJS setting `[debug].display_errors` is enabled.
- **`-e SHELL_COMMAND_OUTPUT_FILE` (optional):** If a path is specified to this command, it will output the shell commands needed to import the papers to the given path, instead of importing them right away (the transformed/ready paper files will be left in place, in order to the commands work). This is probably only useful if your PHP installation has blocked access to the `shell_exec()` function.

Still about the `-f LEVEL`, when creating journals/issues/sections, the script will use the metadata from `${DATA_PATH}/metadata.json` to populate the structures. You're free to review/update anything on this file, as long as you keep the IDs intact. There are basically three options to solve conflicts:
- Increase the "force level": Will create the missing data for you.
- Remove the data from the `${DATA_PATH}/metadata.json`: You can go and remove a whole track/scheduled conference/conference section and proceed, the side-effect is that all linked/related papers won't be imported as well.
- Re-map the data to an existing resource. For example, if you know the conference with `urlPath` "abc" refers to the journal "xyz", you can go and update the `urlPath` to "xyz", then the papers will be imported into this journal.
  - The journal <=> conference mapping is based on the `journals[i].urlPath`
  - The issue <=> schedule conference mapping is based on the `journals[i].issues[j].volume`, `journals[i].issues[j].number` and `journals[i].issues[j].year`
  - The section <=> track is based on the `journals[i].sections[j].title`

> The papers which were imported successfully will be moved to the folder `${DATA_PATH}/processed-papers`.

Setup all arguments and execute the `${OCS_TO_OJS_PATH}/import.php` script, at the end you should see the message `Import finished with success`.

Check if there are not errors/warnings at the script output, perhaps some manual fixes will be needed.

Now you can delete the `${DATA_PATH}` and revert the `[debug].display_errors` back to `Off`.


**Before you move the updates to production, ensure there are no encoding issues, error messages, whether the current active issue is properly set, etc. All papers should be processed properly, failures at the `Native Import Export Plugin` might cause problems which are difficult to debug, this way, if N papers failed, it's better to restart the process from a fresh backup and remove the offending papers from the `papers` folder in order to skip them.**
