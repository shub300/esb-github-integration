
					@yield('page-heading')

					  <div class="content-header-left col-md-12 col-12 p-0 mb-2">
						<div class="row breadcrumbs-top">
						  <div class="col-12" style="display:flex">

							<div class="col-md-7">
							<h3 class="content-header-title float-left mb-0" style="line-height: inherit;">@yield('title')</h3>
							<h4 class="content-header-title float-left mb-0" style="line-height: inherit;margin-top:2px;font-weight:bold">@yield('connection_title')</h4>
							</div>
							<div class="col-md-5">
							<div class="breadcrumb-wrapper">
							  <ol class="breadcrumb">
								<li class="breadcrumb-item"><a href="{{url('integrations')}}">Home</a>
								</li>
								@yield('breadcrumb')
								@if( config('org_details.help_doc_url') || config('org_details.contact_us_url'))
									&nbsp;&nbsp; | &nbsp;&nbsp;
									@if( config('org_details.help_doc_url') )
									<li><a href="{{ config('org_details.help_doc_url') }}" target="_blank"><i class="fa fa-question-circle" aria-hidden="true"></i> Help</a></li> 
									&nbsp;&nbsp;&nbsp;
									@endif
									@if( config('org_details.contact_us_url') )
									<li><a href="{{ config('org_details.contact_us_url') }}" target="_blank"><i class="fa fa-phone-square" aria-hidden="true"></i> Contact Us</a></li>
									@endif
								@endif
							  </ol>
							</div>
							</div>
						  </div>
						  <div class="col-md-12" style="margin-top:5px;">
							<span class="setup-integration-txt" style="color:#636363;font-size:1.286rem;margin-left:15px;">@yield('title2')</span>
						  </div>

						</div>
						<span style="color:#636363;font-size:16px;display:flex;justify-content: left;font-weight: 500;padding:10px 10px -15px 0px">@yield('title3')</span>
					 </div>
					 

					<div class="content-body">
						@yield('page-content')
					</div>