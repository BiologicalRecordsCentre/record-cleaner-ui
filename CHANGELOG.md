# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Message when service is down, [#17](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/17)

### Changed

- Improved efficiency of processing spreadsheets
- Batch process files to prevent timeouts and show progress [#18](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/18)

### Fixed

- Status information being cached
- Field values not clearing when starting again
- Broken link in overview page
- Error counting not correct with large file, [#15](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/15)
- Error importing dates from Excel, [#13](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/13)
- Error outputting additional fields, [#19](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/19)
- Missing help text for 'Use all rules' checkbox, [#22](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/22)

### Removed

## [1.1.0]

### Added

- This CHANGELOG file.
- Upload of Excel files, [#9](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/9)
- VC accignment to output, [#6](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/6)
- Start Again buttons, [#5](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/5)
- Save/Delete settings button and cookie, [#3](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/3)

### Changed

- Updated to support changes in service API.
- ID difficulty moved to verification output, [#12](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/12)
- Allow verification after validation by dropping failed records, [#11](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/11)
- On screen results display improved, [#10](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/10)
- Empty records skipped, [#8](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/8)
- Rule selection to include 'select all', [#7](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/7)
- Rule selection to apply to all sub-selectors, [#7](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/7)
- Results invalidated on going back to previous steps

### Fixed
- Always auto-numbering record id for additional data.
- Verification file link showing before verification, [#4](https://github.com/BiologicalRecordsCentre/record-cleaner-ui/issues/4)
- On-screen summary of additional columns

## [1.0.0]

First release
