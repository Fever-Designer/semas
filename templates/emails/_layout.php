<?php
/**
 * SEMAS Email Layout Wrapper
 * Safe for multiple includes (prevents "cannot redeclare" fatal error)
 */

if (!function_exists('semas_email_open')) {

    function semas_email_open(string $title): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title><?= htmlspecialchars($title) ?></title>
        </head>

        <body style="margin:0;padding:0;background:#F6F7FB;font-family:Arial,Helvetica,sans-serif;color:#1B1F2A;">

        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7FB;padding:24px 0;">
            <tr>
                <td align="center">

                    <table width="560" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border-radius:10px;overflow:hidden;">

                        <!-- HEADER -->
                        <tr>
                            <td style="background:#1E2A52;padding:18px 28px;">
                                <span style="color:#ffffff;font-size:17px;font-weight:bold;">
                                    SEM<span style="color:#D4A24C;">AS</span>
                                </span>
                                <span style="color:#A9B3CC;font-size:12px;">
                                    &nbsp;· UNIVERSITY
                                </span>
                            </td>
                        </tr>

                        <!-- BODY -->
                        <tr>
                            <td style="padding:28px 28px 8px 28px;font-size:14px;line-height:1.6;color:#1B1F2A;">
        <?php
    }
}

if (!function_exists('semas_email_close')) {

    function semas_email_close(): void
    {
        ?>
                            </td>
                        </tr>

                        <!-- FOOTER -->
                        <tr>
                            <td style="padding:16px 28px 24px 28px;font-size:11px;color:#6B7280;border-top:1px solid #E4E7EF;">
                                This is an automated message from SEMAS. Please do not reply directly to this email.
                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

        </body>
        </html>
        <?php
    }
}
?>
