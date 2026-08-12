(function(root, factory)
{
	const matcher = factory();
	if (typeof module === 'object' && module.exports)
	{
		module.exports = matcher;
	}
	if (root)
	{
		root.Grocy = root.Grocy || {};
		root.Grocy.ReceiptImportStoreMatcher = matcher;
	}
})(typeof globalThis !== 'undefined' ? globalThis : this, function()
{
	const CountryAliases = {
		switzerland: ['switzerland', 'schweiz', 'suisse', 'svizzera'],
		germany: ['germany', 'deutschland', 'allemagne', 'germania'],
		france: ['france', 'frankreich', 'francia'],
		italy: ['italy', 'italien', 'italia']
	};
	const CountryByRetailerSuffix = {
		ch: 'switzerland',
		de: 'germany',
		fr: 'france',
		it: 'italy'
	};

	function normalize(value)
	{
		let normalized = String(value || '')
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, ' ')
			.trim();

		Object.entries(CountryAliases).forEach(function([country, aliases])
		{
			aliases.forEach(function(alias)
			{
				normalized = normalized.replace(new RegExp('(^|\\s)' + alias + '(?=\\s|$)', 'g'), '$1' + country);
			});
		});
		return normalized.replace(/\s+/g, ' ').trim();
	}

	function countryHint(preview, retailerName)
	{
		const retailerTokens = retailerName.split(' ');
		const namedCountry = Object.keys(CountryAliases).find(country => retailerTokens.includes(country));
		if (namedCountry)
		{
			return namedCountry;
		}

		const keyParts = String(preview.retailer_key || '').toLowerCase().split('_');
		return CountryByRetailerSuffix[keyParts[keyParts.length - 1]] || null;
	}

	function findLikelyShoppingLocationId(preview, locations)
	{
		const retailerName = normalize(preview.retailer_name);
		const retailerTokens = retailerName.split(' ').filter(Boolean);
		const brand = retailerTokens[0] || '';
		const country = countryHint(preview, retailerName);
		const knownCountries = Object.keys(CountryAliases);

		const candidates = (locations || []).map(function(location)
		{
			const locationName = normalize(location.name);
			const locationTokens = locationName.split(' ').filter(Boolean);
			const locationCountry = knownCountries.find(item => locationTokens.includes(item)) || null;
			let score = 0;

			if (!brand || !locationTokens.includes(brand))
			{
				return { id: Number(location.id), score: 0 };
			}

			if (country && locationCountry && country !== locationCountry)
			{
				return { id: Number(location.id), score: 0 };
			}

			score += 40;
			if (retailerName === locationName)
			{
				score += 60;
			}
			if (country && locationCountry === country)
			{
				score += 50;
			}
			return { id: Number(location.id), score: score };
		}).filter(candidate => candidate.score > 0);

		if (candidates.length === 0)
		{
			return null;
		}
		const highestScore = Math.max(...candidates.map(candidate => candidate.score));
		const bestCandidates = candidates.filter(candidate => candidate.score === highestScore);
		return bestCandidates.length === 1 ? bestCandidates[0].id : null;
	}

	return { findLikelyShoppingLocationId: findLikelyShoppingLocationId };
});
