<?php
// Test fixture: an options panel's labels, which only an administrator sees.
Redux::setSection(
	'bridge_text_theme',
	array(
		'title'  => __( 'Header', 'bridge-text' ),
		// The same words a template shows a visitor: they stay a candidate.
		'desc'   => __( 'Skip to content', 'bridge-text' ),
		'fields' => array( array( 'id' => 'header_padding', 'type' => 'spacing', 'title' => __( 'Control the top and bottom padding of the header.', 'bridge-text' ) ) ),
	)
);
