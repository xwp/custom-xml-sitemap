/**
 * Settings Panel React Component.
 *
 * Provides the admin UI for configuring custom sitemaps using
 * WordPress components and the REST API for term search.
 *
 * @package
 */

import { render, useState, useEffect, useCallback } from '@wordpress/element';
import {
	SelectControl,
	FormTokenField,
	CheckboxControl,
	PanelBody,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Debounce function to limit API calls.
 *
 * @param {Function} func Function to debounce.
 * @param {number}   wait Debounce wait time in milliseconds.
 * @return {Function} Debounced function.
 */
function debounce( func, wait ) {
	let timeout;
	return function executedFunction( ...args ) {
		const later = () => {
			clearTimeout( timeout );
			func( ...args );
		};
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
	};
}

/**
 * Settings Panel Component.
 *
 * Main component for the sitemap configuration interface.
 * Supports two modes:
 * - Posts mode: Lists post URLs organized by date granularity
 * - Terms mode: Lists taxonomy term archive URLs
 *
 * @return {JSX.Element} The settings panel component.
 */
function SettingsPanel() {
	// Get settings from localized script data.
	const {
		postTypes,
		taxonomies,
		savedValues,
		granularities,
		imageOptions,
		modeOptions,
		filterModeOptions,
	} = window.cxsSettings || {};

	// State for form values.
	const [ mode, setMode ] = useState( savedValues?.mode || 'posts' );
	const [ postType, setPostType ] = useState(
		savedValues?.postType || 'post'
	);
	const [ granularity, setGranularity ] = useState(
		savedValues?.granularity || 'month'
	);
	const [ taxonomy, setTaxonomy ] = useState( savedValues?.taxonomy || '' );
	const [ selectedTerms, setSelectedTerms ] = useState( [] );
	const [ termSuggestions, setTermSuggestions ] = useState( [] );
	const [ isLoadingTerms, setIsLoadingTerms ] = useState( false );
	const [ includeImages, setIncludeImages ] = useState(
		savedValues?.includeImages || 'none'
	);
	const [ includeNews, setIncludeNews ] = useState(
		savedValues?.includeNews || false
	);
	const [ termsHideEmpty, setTermsHideEmpty ] = useState(
		savedValues?.termsHideEmpty ?? true
	);
	const [ filterMode, setFilterMode ] = useState(
		savedValues?.filterMode || 'include'
	);

	// Derived state: Check if we're in terms mode.
	const isTermsMode = mode === 'terms';

	// Convert post types object to options array.
	const postTypeOptions = Object.entries( postTypes || {} ).map(
		( [ value, label ] ) => ( {
			value,
			label,
		} )
	);

	// Convert taxonomies object to options array with empty option.
	const taxonomyOptions = [
		{
			value: '',
			label: __( '— No taxonomy filter —', 'custom-xml-sitemap' ),
		},
		...Object.entries( taxonomies || {} ).map( ( [ value, data ] ) => ( {
			value,
			label: data.label,
		} ) ),
	];

	/**
	 * Get the REST base for the current taxonomy.
	 *
	 * @return {string} REST base or empty string.
	 */
	const getRestBase = useCallback( () => {
		if ( ! taxonomy || ! taxonomies?.[ taxonomy ] ) {
			return '';
		}
		return taxonomies[ taxonomy ].rest_base || taxonomy;
	}, [ taxonomy, taxonomies ] );

	/**
	 * Search terms via REST API.
	 *
	 * @param {string} search Search query.
	 */
	const searchTerms = useCallback(
		debounce( async ( search ) => {
			const restBase = getRestBase();
			if ( ! restBase || search.length < 2 ) {
				setTermSuggestions( [] );
				return;
			}

			setIsLoadingTerms( true );

			try {
				const terms = await apiFetch( {
					path: `/wp/v2/${ restBase }?search=${ encodeURIComponent(
						search
					) }&per_page=20&orderby=name&order=asc&hide_empty=false`,
				} );

				setTermSuggestions(
					terms.map( ( term ) => ( {
						id: term.id,
						name: term.name,
					} ) )
				);
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Error searching terms:', error );
				setTermSuggestions( [] );
			} finally {
				setIsLoadingTerms( false );
			}
		}, 300 ),
		[ getRestBase ]
	);

	/**
	 * Load saved terms on mount or when taxonomy changes.
	 */
	useEffect( () => {
		const loadSavedTerms = async () => {
			const restBase = getRestBase();
			const savedTermIds = savedValues?.terms || [];

			if ( ! restBase || savedTermIds.length === 0 ) {
				setSelectedTerms( [] );
				return;
			}

			setIsLoadingTerms( true );

			try {
				const terms = await apiFetch( {
					path: `/wp/v2/${ restBase }?include=${ savedTermIds.join(
						','
					) }&per_page=100`,
				} );

				setSelectedTerms(
					terms.map( ( term ) => ( {
						id: term.id,
						name: term.name,
					} ) )
				);
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Error loading saved terms:', error );
				setSelectedTerms( [] );
			} finally {
				setIsLoadingTerms( false );
			}
		};

		// Only load if we have a taxonomy and the same taxonomy as saved.
		if ( taxonomy && taxonomy === savedValues?.taxonomy ) {
			loadSavedTerms();
		} else {
			setSelectedTerms( [] );
		}
	}, [ taxonomy, savedValues?.taxonomy, savedValues?.terms, getRestBase ] );

	/**
	 * Update hidden input fields when state changes.
	 *
	 * Syncs React state to hidden form inputs for server-side processing.
	 */
	useEffect( () => {
		const modeInput = document.getElementById( 'cxs-sitemap-mode' );
		const postTypeInput = document.getElementById( 'cxs-post-type' );
		const granularityInput = document.getElementById( 'cxs-granularity' );
		const taxonomyInput = document.getElementById( 'cxs-taxonomy' );
		const termsInput = document.getElementById( 'cxs-taxonomy-terms' );
		const includeImagesInput =
			document.getElementById( 'cxs-include-images' );
		const includeNewsInput = document.getElementById( 'cxs-include-news' );
		const termsHideEmptyInput = document.getElementById(
			'cxs-terms-hide-empty'
		);
		const filterModeInput = document.getElementById( 'cxs-filter-mode' );

		if ( modeInput ) {
			modeInput.value = mode;
		}
		if ( postTypeInput ) {
			postTypeInput.value = postType;
		}
		if ( granularityInput ) {
			granularityInput.value = granularity;
		}
		if ( taxonomyInput ) {
			taxonomyInput.value = taxonomy;
		}
		if ( termsInput ) {
			termsInput.value = JSON.stringify(
				selectedTerms.map( ( term ) => term.id )
			);
		}
		if ( includeImagesInput ) {
			includeImagesInput.value = includeImages;
		}
		if ( includeNewsInput ) {
			includeNewsInput.value = includeNews ? '1' : '';
		}
		if ( termsHideEmptyInput ) {
			termsHideEmptyInput.value = termsHideEmpty ? '1' : '0';
		}
		if ( filterModeInput ) {
			filterModeInput.value = filterMode;
		}
	}, [
		mode,
		postType,
		granularity,
		taxonomy,
		selectedTerms,
		includeImages,
		includeNews,
		termsHideEmpty,
		filterMode,
	] );

	/**
	 * Handle taxonomy change.
	 *
	 * @param {string} newTaxonomy New taxonomy value.
	 */
	const handleTaxonomyChange = ( newTaxonomy ) => {
		setTaxonomy( newTaxonomy );
		// Clear terms when taxonomy changes.
		setSelectedTerms( [] );
		setTermSuggestions( [] );
	};

	/**
	 * Handle term token field change.
	 *
	 * @param {Array} tokens Array of token names.
	 */
	const handleTermsChange = ( tokens ) => {
		// Map token names back to term objects.
		const newSelectedTerms = tokens
			.map( ( token ) => {
				// Check if it's already in selected terms.
				const existing = selectedTerms.find(
					( t ) => t.name === token
				);
				if ( existing ) {
					return existing;
				}

				// Check suggestions.
				const suggested = termSuggestions.find(
					( t ) => t.name === token
				);
				if ( suggested ) {
					return suggested;
				}

				return null;
			} )
			.filter( Boolean );

		setSelectedTerms( newSelectedTerms );
	};

	/**
	 * Handle input change for term search.
	 *
	 * @param {string} input Search input.
	 */
	const handleTermInputChange = ( input ) => {
		searchTerms( input );
	};

	return (
		<div className="cxs-settings-panel">
			<PanelBody opened>
				<SelectControl
					label={ __( 'Sitemap Mode', 'custom-xml-sitemap' ) }
					value={ mode }
					options={ modeOptions || [] }
					onChange={ setMode }
					help={ __(
						'Choose whether to list post URLs or taxonomy term archive URLs.',
						'custom-xml-sitemap'
					) }
				/>

				{ ! isTermsMode && (
					<>
						<SelectControl
							label={ __( 'Post Type', 'custom-xml-sitemap' ) }
							value={ postType }
							options={ postTypeOptions }
							onChange={ setPostType }
							help={ __(
								'Select the post type to include in this sitemap.',
								'custom-xml-sitemap'
							) }
						/>

						<SelectControl
							label={ __( 'Granularity', 'custom-xml-sitemap' ) }
							value={ granularity }
							options={ granularities }
							onChange={ setGranularity }
							help={ __(
								'Choose the date-based hierarchy level for sitemap files.',
								'custom-xml-sitemap'
							) }
						/>
					</>
				) }

				<SelectControl
					label={
						isTermsMode
							? __( 'Taxonomy', 'custom-xml-sitemap' )
							: __( 'Taxonomy Filter', 'custom-xml-sitemap' )
					}
					value={ taxonomy }
					options={
						isTermsMode
							? taxonomyOptions.filter( ( opt ) => opt.value )
							: taxonomyOptions
					}
					onChange={ handleTaxonomyChange }
					help={
						isTermsMode
							? __(
									'Select the taxonomy whose term archives will be listed.',
									'custom-xml-sitemap'
							  )
							: __(
									'Optionally filter posts by a specific taxonomy.',
									'custom-xml-sitemap'
							  )
					}
				/>

				{ ! isTermsMode && taxonomy && (
					<div className="cxs-terms-field">
						<FormTokenField
							label={ __(
								'Filter by Terms',
								'custom-xml-sitemap'
							) }
							value={ selectedTerms.map( ( t ) => t.name ) }
							suggestions={ termSuggestions.map(
								( t ) => t.name
							) }
							onChange={ handleTermsChange }
							onInputChange={ handleTermInputChange }
							placeholder={ __(
								'Type to search terms…',
								'custom-xml-sitemap'
							) }
						/>
						{ isLoadingTerms && (
							<div className="cxs-loading">
								<Spinner />
							</div>
						) }
						<p className="description">
							{ __(
								'Leave empty to include all posts with any term in this taxonomy.',
								'custom-xml-sitemap'
							) }
						</p>
					</div>
				) }

				{ ! isTermsMode && taxonomy && selectedTerms.length > 0 && (
					<SelectControl
						label={ __( 'Filter Mode', 'custom-xml-sitemap' ) }
						value={ filterMode }
						options={ filterModeOptions || [] }
						onChange={ setFilterMode }
						help={ __(
							'Choose whether to include or exclude posts with the selected terms.',
							'custom-xml-sitemap'
						) }
					/>
				) }

				{ isTermsMode && (
					<CheckboxControl
						label={ __( 'Hide Empty Terms', 'custom-xml-sitemap' ) }
						checked={ termsHideEmpty }
						onChange={ setTermsHideEmpty }
						help={ __(
							'When enabled, terms with no published posts will be excluded from the sitemap.',
							'custom-xml-sitemap'
						) }
					/>
				) }

				{ ! isTermsMode && (
					<>
						<SelectControl
							label={ __(
								'Include Images',
								'custom-xml-sitemap'
							) }
							value={ includeImages }
							options={ imageOptions || [] }
							onChange={ setIncludeImages }
							help={ __(
								'Add image metadata to sitemap entries for Google Image Search.',
								'custom-xml-sitemap'
							) }
						/>

						<CheckboxControl
							label={ __(
								'Include News Metadata',
								'custom-xml-sitemap'
							) }
							checked={ includeNews }
							onChange={ setIncludeNews }
							help={ __(
								'Add news publication metadata for Google News sitemaps.',
								'custom-xml-sitemap'
							) }
						/>
					</>
				) }
			</PanelBody>
		</div>
	);
}

// Initialize when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'cxs-settings-panel' );
	if ( container ) {
		render( <SettingsPanel />, container );
	}
} );
