<?php
require_once INCLUDE_DIR . 'class.plugin.php';
class ArchiverPluginConfig extends PluginConfig {
	// Provide compatibility function for versions of osTicket prior to
	// translation support (v1.9.4)
	function translate() {
		if (! method_exists ( 'Plugin', 'translate' )) {
			return array (
					function ($x) {
						return $x;
					},
					function ($x, $y, $n) {
						return $n != 1 ? $y : $x;
					} 
			);
		}
		return Plugin::translate ( 'archiver' );
	}
	
	/**
	 * Build an Admin settings page.
	 *
	 * {@inheritdoc}
	 *
	 * @see PluginConfig::getOptions()
	 */
	function getOptions() {
		list ( $__, $_N ) = self::translate ();
		return array (
				'archive-break' => new SectionBreakField ( array (
						'label' => $__ ( 'Archive Configuration' ) 
				) ),
				'mode' => new ChoiceField ( array (
						'label' => $__ ( 'Archive type' ),
						'choices' => array (
								'basic' => $__ ( 'Basic Archive Mode (save a PDF only).' ),
								'advanced' => $__ ( 'Advanced Archive Mode (stores all attachments and metadata in a folder per ticket.' ) 
						),
						'default' => 'basic',
						'hint' => $__ ( 'Either PDF only or Everything' ) 
				) ),
				'archive-path' => new TextboxField ( array (
						'label' => $__ ( 'Storage Location' ),
						'hint' => $__ ( 'Something that is not publically accessible would be good, NOT THE SAME AS ATTACHMENTS PATH!.' ),
						'size' => 80,
						'length' => 256,
						'placeholder' => '/opt/tickets/archives',
						'required' => true 
				) ),
				'include-nodes' => new Booleanfield ( array (
						'label' => $__ ( 'Include notes in Archived Tickets' ) 
				) ),
				'purge-break' => new SectionBreakField ( array (
						'label' => $__ ( 'Auto Purge' ) 
				) ),
				'purge' => new BooleanField ( array (
						'label' => $__ ( 'Auto-purge old closed tickets' ),
						'hint' => $__ ( '' ) 
				) ),
				'purge-age' => new TextboxField ( array (
						'default' => '999',
						'label' => $__ ( 'Max Age (in months) for tickets' ),
						'hint' => $__ ( 'Keep everything younger than this. 999 months = 83 years' ),
						'size' => 5,
						'length' => 3  // 83.25 years
				) ),
				'purge-frequency' => new ChoiceField ( array (
						'label' => $__ ( 'Purge Frequency' ),
						'choices' => array (
								'12' => $__ ( '12 Hours' ),
								'24' => $__ ( '1 Day' ),
								'36' => $__ ( '36 Hours' ),
								'48' => $__ ( '2 Days' ),
								'72' => $__ ( '72 Hours' ),
								'168' => $__ ( '1 Week' ) 
						),
						'default' => '24',
						'hint' => $__ ( "How often should we archive & purge old tickets?" ) 
				) ),
				'purge-num' => new TextboxField ( array (
						'label' => $__ ( 'Purge Number' ),
						'hint' => $__ ( "How many tickets should we purge each time?" ),
						'default' => 10 
				) ) 
		);
	}
}