{include file="_head.tpl" title="Prestataires de paiement" current="providers"}

{include file="payments/_menu.tpl"}

<h2 class="ruler">Liste des prestataires de paiement</h2>

{include file="common/dynamic_list_head.tpl" list=$providers}

	<tbody>

	{foreach from=$providers->iterate() item="row"}
		<tr>
			<td class="num">{$row.id}</td>
			<td>{$row.name}</td>
			<td>{$row.label}</td>
			<td class="actions"></td>
		</tr>
	{/foreach}

	</tbody>
</table>

{$providers->getHTMLPagination()|raw}

<p class="help block">Vous pouvez ajouter des prestataires supplémentaires en {link href="https://paheko.cloud/installation-desactivation-extensions-integrees" label="installant des extensions de paiement"}.</p>

{include file="_foot.tpl"}