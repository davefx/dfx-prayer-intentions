<?php
/**
 * Per-group permissions.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides who may see and manage what. Every panel query is filtered through
 * the groups returned here.
 */
final class DFXPRAY_Permissions {

	use DFXPRAY_Singleton;

	/**
	 * User meta key remembering the active group in the panel.
	 */
	private const ACTIVE_GROUP_META = 'dfxpray_active_group';

	/**
	 * Whether the user is a WordPress administrator for the plugin's purposes.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 */
	public function is_admin( ?int $user_id = null ): bool {
		return user_can( $user_id ?? get_current_user_id(), 'manage_options' );
	}

	/**
	 * Whether the user may open the leaders' panel at all.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 */
	public function can_access_panel( ?int $user_id = null ): bool {
		return user_can( $user_id ?? get_current_user_id(), DFXPRAY_Installer::CAPABILITY );
	}

	/**
	 * IDs of the active groups the user may manage.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return int[]
	 */
	public function group_ids_for_user( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! $this->can_access_panel( $user_id ) ) {
			return array();
		}

		$active = array_column( DFXPRAY_Groups::get_instance()->all( 'active' ), 'id' );

		if ( $this->is_admin( $user_id ) ) {
			return $active;
		}

		return array_values( array_intersect( $active, DFXPRAY_Groups::get_instance()->get_group_ids_for_leader( $user_id ) ) );
	}

	/**
	 * Whether the user may manage a group.
	 *
	 * @param int      $group_id Group ID.
	 * @param int|null $user_id  User ID; defaults to the current user.
	 */
	public function can_manage_group( int $group_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! $this->can_access_panel( $user_id ) ) {
			return false;
		}

		if ( $this->is_admin( $user_id ) ) {
			return null !== DFXPRAY_Groups::get_instance()->get( $group_id );
		}

		return in_array( $group_id, $this->group_ids_for_user( $user_id ), true );
	}

	/**
	 * The group the user is working on in the panel.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return int Group ID, or 0 when the user has no groups.
	 */
	public function get_active_group( ?int $user_id = null ): int {
		$user_id = $user_id ?? get_current_user_id();
		$groups  = $this->group_ids_for_user( $user_id );

		if ( ! $groups ) {
			return 0;
		}

		$stored = (int) get_user_meta( $user_id, self::ACTIVE_GROUP_META, true );

		return in_array( $stored, $groups, true ) ? $stored : $groups[0];
	}

	/**
	 * Switch the user's active group.
	 *
	 * @param int      $group_id Group ID.
	 * @param int|null $user_id  User ID; defaults to the current user.
	 * @return bool Whether the switch happened.
	 */
	public function set_active_group( int $group_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! in_array( $group_id, $this->group_ids_for_user( $user_id ), true ) ) {
			return false;
		}

		update_user_meta( $user_id, self::ACTIVE_GROUP_META, $group_id );

		return true;
	}

	/**
	 * Roles whose access to the panel the administrator may decide:
	 * every role except administrators and leaders, which always have it.
	 *
	 * @return array<string, string> Role => translated name.
	 */
	public function configurable_roles(): array {
		$roles = array();

		foreach ( wp_roles()->role_names as $role => $name ) {
			if ( ! in_array( $role, array( 'administrator', DFXPRAY_Installer::ROLE ), true ) ) {
				$roles[ $role ] = translate_user_role( $name );
			}
		}

		return $roles;
	}

	/**
	 * Configurable roles that currently reach the panel.
	 *
	 * @return string[]
	 */
	public function panel_roles(): array {
		return array_values(
			array_filter(
				array_keys( $this->configurable_roles() ),
				static fn( $role ) => get_role( $role ) && get_role( $role )->has_cap( DFXPRAY_Installer::CAPABILITY )
			)
		);
	}

	/**
	 * Give the panel capability to exactly these configurable roles.
	 *
	 * @param string[] $roles Roles.
	 */
	public function set_panel_roles( array $roles ): void {
		foreach ( array_keys( $this->configurable_roles() ) as $role ) {
			$object = get_role( $role );
			if ( ! $object ) {
				continue;
			}

			if ( in_array( $role, $roles, true ) ) {
				$object->add_cap( DFXPRAY_Installer::CAPABILITY );
			} else {
				$object->remove_cap( DFXPRAY_Installer::CAPABILITY );
			}
		}
	}

	/**
	 * Whether the user reaches the admin only for the panel (leaders), as
	 * opposed to editors or administrators who were also given access.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 */
	public function is_panel_only_user( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return $this->can_access_panel( $user_id ) && ! user_can( $user_id, 'edit_posts' );
	}
}
