
<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/3pl.png' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST"  class="3plform">
                    @csrf
                     @include('flash.flash_message')
                     <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">Client ID *</label>
                                <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID" >
                                <small class="field_error">@lang('tagscript.error_msg')</small>
                                @error('client_id')
                                <small class="error">{{ $message }}</small>
                                @enderror
                        </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">Client Secret *</label>
                            <input type="text" class="form-control" id="client_secret" name="client_secret" placeholder="Client Secret" >
                            <small class="field_error">@lang('tagscript.error_msg')</small>
                            @error('client_secret')
                            <small class="error">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>
                        </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">User Login ID *</label>
                                <input type="text"  class="form-control number_only" id="user_login_id" name="user_login_id" placeholder="User Login ID" >
                                <small class="field_error">@lang('tagscript.error_msg')</small>
                                @error('user_login_id')
                                <small class="error">{{ $message }}</small>
                                @enderror
                        </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">TPL * <i class="fa fa-info-circle" aria-hidden="true" data-toggle="tooltip" data-placement="top" title="Enter TPL value without {}" data-original-title="Enter TPL value without {}" ></i></label>
                                <input type="text"  class="form-control" id="tpl" name="tpl" placeholder="TPL" >
                                <small class="field_error">@lang('tagscript.error_msg')</small>
                                @error('tpl')
                                <small class="error">{{ $message }}</small>
                                @enderror
                        </div>
                    </div>
                        </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">Default Customer ID *</label>
                                <input type="text"  class="form-control number_only" id="default_customer_id" name="default_customer_id" placeholder="Default Customer ID" >
                                <small class="field_error">@lang('tagscript.error_msg')</small>
                                @error('default_customer_id')
                                <small class="error">{{ $message }}</small>
                                @enderror
                        </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="my-label">Default Facility ID *</label>
                            <input type="text"  class="form-control number_only" id="default_facility_id" name="default_facility_id" placeholder="Default Facility ID" >
                            <small class="field_error">@lang('tagscript.error_msg')</small>
                            @error('default_facility_id')
                            <small class="error">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="my-label">Domain Name *</label>
                                <input type="text"  class="form-control" id="domain" name="domain" placeholder="Domain Name" >
                                <small class="field_error">@lang('tagscript.error_msg')</small>
                                @error('domain')
                                <small class="error">{{ $message }}</small>
                                @enderror
                        </div>
                        </div>
                    </div>
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" class="btn btn-primary waves-effect waves-float waves-light  w-100" data-text='Connect Now' id="btnSubmit3pl">
                                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Modal to comfirm account secret submission -->
<div class="modal fade text-start modal-primary " id="mdl3pl" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered modal-xs">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given 3PL account secrets?</label>

				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connect3pl()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>


<script src="{{asset('public/js/pages/auth_3pl.js')}}"></script>


