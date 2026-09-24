const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

module.exports = async () => {
	if ( process.env.DFXPRAY_E2E_SKIP_SETUP ) {
		return;
	}
	execFileSync( path.join( __dirname, 'bin', 'setup-site.sh' ), { stdio: 'inherit' } );
};
