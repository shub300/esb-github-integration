<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                @if($platform == 'amazonvendor')
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/amazon_logo.png' }}">
                @elseif($platform == 'amazonmcf')
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/amazonmcf.png' }}">
                @else
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/amazon_logo.png' }}">
                @endif
			</div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
				</h4>
                <form method="POST" class="amazon_connect_form">
                    @csrf
                    <input type="hidden" name="platform_name" value="{{ $platform }}" />
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="amazon_account_name" name="amazon_account_name" placeholder="Example : Amazon Account 1" autocomplete="off" data-toggle="tooltip" data-placement="top" title="Any random name for multiple accounts identifier." >
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Access Key</label>
                        <input type="text" class="form-control" id="access_key" name="access_key" placeholder="Access Key" autocomplete="off">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Secret Key</label>
                        <input type="text" class="form-control" id="secret_key" name="secret_key" placeholder="Secret Key">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Role ARN</label>
                        <input type="text" class="form-control" id="role_arn" name="role_arn" placeholder="Role ARN">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Selling Region</label>
                        <select class="form-control" id="region" name="region" placeholder="Region">
                            <option value="">Select Region</option>
                            <option value="us-west-2">Far East (Australia, Japan and Singapore marketplaces)</option>
                            <option value="eu-west-1">Europe (Egypt, France, Germany, India, Italy, Netherlands, Poland, Spain, Sweden, Turkey, United Arab Emirates and United Kingdom and marketplaces)</option>
                            <option value="us-east-1">North America (Brazil, Canada, Mexico and United States marketplaces)</option>
						</select>
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Marketplace ID</label>
                        <select class="form-control" id="market_place_id" name="market_place_id">
                            <option value=""> Select Marketplace ID</option>
                            <optgroup label="Far East Region">
                                <option value="A39IBJ37TRP1C6">Australia - A39IBJ37TRP1C6</option>
                                <option value="A1VC38T7YXB528">Japan - A1VC38T7YXB528</option>
                                <option value="A19VAU5U5O7RUS">Singapore - A19VAU5U5O7RUS</option>
                            </optgroup>
                            <optgroup label="Europe Region">
                                <option value="ARBP9OOSHTCHU">Egypt - ARBP9OOSHTCHU</option>
                                <option value="A13V1IB3VIYZZH">France - A13V1IB3VIYZZH</option>
                                <option value="A1PA6795UKMFR9">Germany - A1PA6795UKMFR9</option>
                                <option value="A21TJRUUN4KGV">India - A21TJRUUN4KGV</option>
                                <option value="APJ6JRA9NG5V4">Italy - APJ6JRA9NG5V4</option>
                                <option value="A1805IZSGTT6HS">Netherlands - A1805IZSGTT6HS</option>
                                <option value="A1C3SOZRARQ6R3">Poland - A1C3SOZRARQ6R3</option>
                                <option value="A17E79C6D8DWNP">Saudi Arabia - A17E79C6D8DWNP</option>
                                <option value="A1RKKUPIHCS9HS">Spain - A1RKKUPIHCS9HS</option>
                                <option value="A2NODRKZP88ZB9">Sweden - A2NODRKZP88ZB9</option>
                                <option value="A33AVAJ2PDY3EV">Turkey - A33AVAJ2PDY3EV</option>
                                <option value="A2VIGQ35RCS4UG">United Arab Emirates - A2VIGQ35RCS4UG</option>
                                <option value="A1F83G8C2ARO7P">United Kingdom - A1F83G8C2ARO7P</option>
                            </optgroup>
                            <optgroup label="North America Region">
                                <option value="A2Q3Y263D00KWC">Brazil - A2Q3Y263D00KWC</option>
                                <option value="A2EUQ1WTGCTBG2">Canada - A2EUQ1WTGCTBG2</option>
                                <option value="A1AM78C64UM0Y8">Mexico - A1AM78C64UM0Y8</option>
                                <option value="ATVPDKIKX0DER">United States - ATVPDKIKX0DER</option>
                            </optgroup>
						</select>
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Client ID</label>
                        <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Client Secret</label>
                        <input type="text" class="form-control" id="client_secret" name="client_secret" placeholder="Client Secret">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1">
                        <label class="my-label">Refresh Token</label>
                        <input type="text" class="form-control" id="refresh_token" name="refresh_token" placeholder="Refresh Token">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
					</div>
                    <div class="mb-1 row" style="margin-left: 2px;">
                        <label>Sandbox</label>&nbsp;<div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="env_type" name="env_type" value="on" checked>
                            <label class="custom-control-label" for="env_type"></label>
						</div><label>&nbsp;Production</label>
					</div>
					
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitAmazon" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now'>
                                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now </span>
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<!-- Modal to comfirm account secret submission -->
<div class="modal fade text-start modal-primary" id="mdlAmazon" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Amazon account secrets?</label>
					<h6><b>Account Name:&nbsp;</b><small id="mdlAmazon_acc_name"></small></h6>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectAmazon()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_amazon.js')}}"></script>