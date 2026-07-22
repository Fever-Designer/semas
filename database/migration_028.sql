-- SEMAS - Migration 028: Replace institution-specific branding
USE semas;

UPDATE system_settings
SET setting_value = 'UNIVERSITY'
WHERE setting_key = 'university_name'
  AND HEX(UPPER(TRIM(setting_value))) IN (
      '554E4956455253495459204F46204B4947414C49',
      '554E49564552535459204F46204B4947414C49'
  );
