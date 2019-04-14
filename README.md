# osTicket plugin: Archiver

Enables PDF Archiving of tickets before they delete, and a basic auto-delete of old tickets via schedule.

# Enable automatic Deleting of old tickets (no Modifications required)
-  Requires cron to be enabled and working.
-  Requires this plugin installed

Install as a normal plugin (extract/checkout into `/include/plugins/archiver`), don't change core at all, enable the plugin via the admin screen and tick the box ```Auto-purge old closed tickets```. Configure it as you need for your environment.

## Max Age Setting
How old is too old? If you specify an max-age for closed tickets, the plugin will delete them for you when the maximum age you specify is reached.

## Purge Frequency Setting
We store when it last ran the purge, and depending on your settings, will run it again provided cron is called often enough. EG: If you want it to run every 12 hours, but only run cron every 24, not much we can do about that.

## Purge Number Setting
How many tickets do we purge every time we run? Depending on how you've configured it, this can be high (command-line mode for this) or low (for auto-cron), if you have a high-volume of tickets and auto-cron, you will need to set this to run as often as possible.



# To Install as MOD and enable Archiving during any ticket delete:
Open `/include/class.ticket.php` and add the "Signal::send" line below:

```php
    function delete($comments='') {
        global $ost, $thisstaff;
        
        Signal::send('ticket.before.delete', $this); // Archiver plugin: Added to archive tickets before deleting them.
```
- Alternatively, you can copy the file provided from the subfolders (osticket-$VERSION) into /include/ and overwrite the core file. If you change to a version not provided, or update etc, then use the above method instead and open an issue so I can make a new version.

- Admin options allow you to specify how old tickets have to be before purge 
- Admin option defines where to dump old tickets

## Important: Update Requirement for Archive Mod
After each update, go into the plugin settings and push Save, this will check that the `Signal` is still there, otherwise it will need to be added again.  
I haven't found a way of detecting the upgrade without doing it on bootstrap, which is bad from a performance perspective. 

## Archive Mode & structure (plan):

if "Advanced Archive" mode enabled, then:
- /path/to/archives/{$department}/{$user}/{$ticket_subject}_{$ticket_id}/
- .. /attachment_filename1.ext
- .. /attachment_filename2.ext
- .. /data.json (everything we know about the ticket, entire thread, with all metadata retained as much as possible, including original mail headers, think Australian Government level of metadata retention)
- .. /Ticket.pdf (export via normal PDF semantics)
- Folder mtime set to initial ticket date. 

if "Basic Archive" mode enabled, then:
- /path/to/archives/{$ticket_subject}_{$ticket_id}.pdf (export via normal PDF semantics)

If there is interest, or pull-requests, I'll eventually (maybe) finish the advanced archive mode.
