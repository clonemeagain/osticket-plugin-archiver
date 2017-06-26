# osTicket plugin: Archiver

Enables Archive of tickets before delete, and auto-delete/archive of old tickets.

## To Install
Open /include/class.ticket.php and add the line below:

```php
    function delete($comments='') {
        global $ost, $thisstaff;
        
        Signal::send('ticket.before.delete', $this); // Archiver plugin: Added to archive tickets before deleting them.
```

- Admin options allow you to specify how old tickets have to be before purge 
- Admin option defines where to dump old tickets

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


## Purge Age Setting
Depending on Admin "purge age", we listen to a new ticket signal, check the last time we ran (we'll store it in config)

$this->getConfig('lastrun') for instance..