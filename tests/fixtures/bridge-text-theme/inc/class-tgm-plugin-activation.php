<?php
// Test fixture: a bundled plugin installer, whose notices only an administrator sees.
class TGM_Plugin_Activation {
	public function strings() {
		return array( 'page_title' => __( 'Install Required Plugins', 'bridge-text' ) );
	}
}
