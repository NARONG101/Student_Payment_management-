<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Student;
use App\Models\PaymentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /** Allowed time slots — validated server-side */
    private const TIME_SLOTS = [
        'mon-fri 7:00-9:00',
        'mon-fri 9:00-11:00',
        'mon-fri 1:00-3:00',
        'mon-fri 3:00-5:00',
        'mon-fri 5:30-7:30',
        'sat-sun 7:00-11:00',
        'sat-sun 1:00-5:00',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // NEXT PAYMENT DATE — single source of truth is Student::nextPaymentDateFrom
    // ─────────────────────────────────────────────────────────────────────────
    public static function calcNextPaymentDate(Carbon $paidDate, int $paymentDay): Carbon
    {
        return Student::nextPaymentDateFrom($paidDate, $paymentDay);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activeStudentsWithLastPayment(): \Illuminate\Database\Eloquent\Builder
    {
        // Order by next_payment_date DESC so the payment that covers
        // the furthest month forward is always first.
        // This handles the case where someone pays back-months on the same day.
        return Student::where(function ($q) {
            $q->where('status', 'active')->orWhereNull('status');
        })->with(['payments' => fn ($q) => $q->orderByDesc('next_payment_date')
                                             ->orderByDesc('id')]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        // Query payments directly — much simpler and correct sorting
        $query = \App\Models\Payment::with('student')
            ->whereHas('student', function ($q) {
                $q->where('status', 'active')->orWhereNull('status');
            });

        // Filter by class type (weekday = mon-fri, weekend = sat-sun)
        $classType = $request->get('class_type', '');
        if ($classType === 'weekday') {
            $query->where('time_type', 'like', 'mon-fri%');
        } elseif ($classType === 'weekend') {
            $query->where('time_type', 'like', 'sat-sun%');
        }

        // Sort
        $sortBy = $request->get('sort_by', 'id');
        $payments = match ($sortBy) {
            'date'  => $query->orderByDesc('due_date')->orderByDesc('id')->get(),
            'grade' => $query->get()
                             ->sortByDesc(fn ($p) => $p->student?->year_level ?? 0)
                             ->values(),
            default => $query->orderByDesc('id')->get(), // newest first
        };

        // Get payment method counts
        $paymentMethodCounts = $payments->groupBy('payment_method')->map(fn ($group) => $group->count());

        return view('payments.index', compact('payments', 'classType', 'paymentMethodCounts'));
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(Request $request)
    {
        $students = Student::where(function ($q) {
            $q->where('status', 'active')->orWhereNull('status');
        })->with(['payments' => fn ($q) => $q->orderByDesc('next_payment_date')->orderByDesc('id')])
          ->orderBy('first_name')
          ->get();

        $selectedStudentId = $request->query('student_id');
        $selectedStudent = null;
        
        if ($selectedStudentId) {
            $student = Student::where(function ($q) {
                    $q->where('status', 'active')->orWhereNull('status');
                })
                ->with(['payments' => fn ($q) => $q->orderByDesc('next_payment_date')->orderByDesc('id')])
                ->find($selectedStudentId);
                
            if ($student) {
                $lastPay = $student->payments->first();
                $payDay = (int)($student->monthly_payment_day ?? 1);

                if ($lastPay && $lastPay->next_payment_date) {
                    $firstOwed = $lastPay->next_payment_date->format('Y-m-d');
                } elseif ($lastPay && $lastPay->payment_date) {
                    $firstOwed = \App\Models\Student::nextPaymentDateFrom(
                        \Carbon\Carbon::parse($lastPay->payment_date),
                        $payDay
                    )->format('Y-m-d');
                } else {
                    $firstOwed = $student->enrollment_date->format('Y-m-d');
                }

                $selectedStudent = [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'year_level' => $student->year_level,
                    'gender' => $student->gender ?? 'n/a',
                    'monthly_fee' => (float)$student->monthly_fee,
                    'discount' => (float)($student->discount ?? 0),
                    'payment_day' => $payDay,
                    'time_types' => $student->time_types ?? [],
                    'first_owed' => $firstOwed,
                ];
            }
        }
        
        return view('payments.create', compact('students', 'selectedStudentId', 'selectedStudent'));
    }

    // ── Store (handles both AJAX and normal POST) ─────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id'        => 'required|exists:students,id',
            'payment_date'      => 'required|date',
            'covering_month'    => 'required|date',
            'next_payment_date' => 'nullable|date',
            'time_types'        => 'required|array|min:1',
            'time_types.*'      => 'in:' . implode(',', self::TIME_SLOTS),
            'months_covered'    => 'required|integer|min:1|max:12',
            'payment_method'    => 'required|in:cash,aba,ac,auto',
            'amount_due'        => 'nullable|numeric|min:0',
            'admin_fee'         => 'nullable|numeric|min:0',
            'discount'          => 'nullable|numeric|min:0|max:100',
            'photo'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'notes'             => 'nullable|string|max:500',
        ]);

        $student       = Student::findOrFail($validated['student_id']);
        $paidDate      = Carbon::parse($validated['payment_date']);
        $coveringMonth = Carbon::parse($validated['covering_month'])->startOfMonth();
        $paymentDay    = (int) ($student->monthly_payment_day ?? $paidDate->day);
        $monthsCovered = $validated['months_covered'];

        // Next due = payment_day of month AFTER the covering month plus months covered
        if (!empty($validated['next_payment_date'])) {
            $nextDate = Carbon::parse($validated['next_payment_date']);
        } else {
            $lastCoveringMonth = $coveringMonth->copy()->addMonths($monthsCovered - 1);
            $nextDate = Student::nextPaymentDateFrom($lastCoveringMonth, $paymentDay);
        }

        // due_date = payment_day within the covering month (safe clamp)
        $lastDayCovering = (int) $coveringMonth->copy()->endOfMonth()->day;
        $dueDate         = $coveringMonth->copy()->day(min($paymentDay, $lastDayCovering));

        // Calculate amounts (multiply by months covered, discount on monthly fee only)
        $baseMonthlyFee = $validated['amount_due'] ?? (float)$student->monthly_fee;
        $amountDue = $baseMonthlyFee * $monthsCovered;
        $adminFee = 0;
        $discount = $validated['discount'] ?? ($student->discount ?? 0);
        $discountAmount = ($baseMonthlyFee * $monthsCovered) * ($discount / 100);
        $totalDue = ($amountDue - $discountAmount) + $adminFee;
        $amountPaid = $totalDue; // Always fully paid
        $balance = max(0, $totalDue - $amountPaid);

        $validated['receipt_number']    = Payment::generateReceiptNumber();
        $validated['amount_due']        = $amountDue;
        $validated['admin_fee']         = $adminFee;
        $validated['discount']          = $discount;
        $validated['amount_paid']        = $amountPaid;
        $validated['balance']           = $balance;
        $validated['status']            = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'pending');
        $validated['created_by']        = Auth::id();
        $validated['due_date']          = $dueDate;
        $validated['deadline_date']     = $dueDate;
        $validated['next_payment_date'] = $nextDate;
        $validated['payment_date']      = $paidDate;

        if (empty($validated['notes'])) {
            if ($monthsCovered == 1) {
                $validated['notes'] = 'Payment for ' . $coveringMonth->format('F Y');
            } else {
                $lastMonth = $coveringMonth->copy()->addMonths($monthsCovered -1);
                $validated['notes'] = 'Payment for ' . $coveringMonth->format('F Y') . ' - ' . $lastMonth->format('F Y');
            }
        }

        unset($validated['covering_month']);
        if (isset($validated['time_type'])) {
            unset($validated['time_type']);
        }

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('payments/photos', 'public');
        } elseif ($request->filled('captured_photo_data')) {
            // Save base64 camera capture as JPEG file
            $data     = $request->input('captured_photo_data');
            $data     = preg_replace('/^data:image\/\w+;base64,/', '', $data);
            $decoded  = base64_decode($data);
            $filename = 'payments/photos/cam_' . uniqid() . '.jpg';
            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $decoded);
            $validated['photo'] = $filename;
        }

        $payment = Payment::create($validated);

        // Always redirect to payment detail page so user can see receipt, print, or continue
        return redirect()
            ->route('payments.show', $payment)
            ->with('success', 'Payment recorded for ' . $coveringMonth->format('F Y') . '! Receipt #' . $payment->receipt_number);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(Payment $payment)
    {
        $payment->load(['student', 'paymentType', 'creator']);
        return view('payments.show', compact('payment'));
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(Payment $payment)
    {
        $paymentTypes = PaymentType::where('is_active', true)->get();
        return view('payments.edit', compact('payment', 'paymentTypes'));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'payment_date'      => 'nullable|date',
            'payment_method'    => 'required|in:cash,aba,ac,auto',
            'time_types'        => 'required|array|min:1',
            'time_types.*'      => 'in:' . implode(',', self::TIME_SLOTS),
            'months_covered'    => 'required|integer|min:1|max:12',
            'photo'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'deadline_date'     => 'required|date',
            'next_payment_date' => 'nullable|date',
            'amount_due'        => 'nullable|numeric|min:0',
            'admin_fee'         => 'nullable|numeric|min:0',
            'discount'          => 'nullable|numeric|min:0|max:100',
            'amount_paid'       => 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:500',
        ]);

        // If amount_due, admin_fee, or discount are provided, recalculate amount_paid and balance
        if (isset($validated['amount_due']) || isset($validated['admin_fee']) || isset($validated['discount'])) {
            $amountDue = $validated['amount_due'] ?? $payment->amount_due;
            $adminFee = $validated['admin_fee'] ?? $payment->admin_fee;
            $discount = $validated['discount'] ?? $payment->discount;
            
            // Discount applied only to amount_due, not including admin fee
            $discountAmount = $amountDue * ($discount / 100);
            $subtotal = ($amountDue - $discountAmount) + $adminFee;
            $amountPaid = $validated['amount_paid'] ?? $subtotal;
            $balance = max(0, $subtotal - $amountPaid);
            
            $validated['amount_due'] = $amountDue;
            $validated['admin_fee'] = $adminFee;
            $validated['discount'] = $discount;
            $validated['amount_paid'] = $amountPaid;
            $validated['balance'] = $balance;
            
            // Update status based on balance
            if ($balance <= 0) {
                $validated['status'] = 'paid';
            } elseif ($amountPaid > 0) {
                $validated['status'] = 'partial';
            } else {
                $validated['status'] = 'pending';
            }
        }

        if ($request->hasFile('photo')) {
            if ($payment->photo) {
                Storage::disk('public')->delete($payment->photo);
            }
            $validated['photo'] = $request->file('photo')->store('payments/photos', 'public');
        }

        $payment->update($validated);

        return redirect()->route('payments.show', $payment)
            ->with('success', 'Payment updated successfully!');
    }

    // ── CSV Export — All Payments ─────────────────────────────────────────────

    public function exportCsv(Request $request)
    {
        $query = Payment::with('student')
            ->whereHas('student', function ($q) {
                $q->where('status', 'active')->orWhereNull('status');
            });

        // Filter by class type
        $classType = $request->get('class_type', '');
        if ($classType === 'weekday') {
            $query->where('time_type', 'like', 'mon-fri%');
        } elseif ($classType === 'weekend') {
            $query->where('time_type', 'like', 'sat-sun%');
        }

        $sortBy = $request->get('sort_by', 'id');
        $payments = match ($sortBy) {
            'date'  => $query->orderByDesc('due_date')->orderByDesc('id')->get(),
            'grade' => $query->get()->sortByDesc(fn ($p) => $p->student?->year_level ?? 0)->values(),
            default => $query->orderByDesc('id')->get(),
        };

        $classLabel = match($classType) {
            'weekday' => '_weekday',
            'weekend' => '_weekend',
            default   => '',
        };

        $filename = 'payments' . $classLabel . '_' . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($payments, $classType) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM

            $classLabel = match($classType) {
                'weekday' => ' (' . __('app.weekday_class') . ')',
                'weekend' => ' (' . __('app.weekend_class') . ')',
                default   => '',
            };

            fputcsv($handle, [__('app.all_payments') . $classLabel . ' — ' . now()->format('d/m/Y')]);
            fputcsv($handle, []);

            fputcsv($handle, [
                __('app.receipt'), __('app.student_id'), __('app.first_name') . ' ' . __('app.last_name'),
                __('app.grade'), __('app.weekday') . '/' . __('app.weekend'),
                __('app.amount'), 'Admin Fee', 'Discount (%)', __('app.paid'),
                'Balance', 'Payment Date', 'Due Date', 'Next Payment',
                __('app.status'), __('app.payment_method'), __('app.time_type'), __('app.notes'),
            ]);

            // Group by grade
            $byGrade = $payments->groupBy(fn($p) => $p->student?->year_level ?? '?')->toArray();
            ksort($byGrade);
            foreach ($byGrade as $grade => $gradePayments) {
                fputcsv($handle, []);
                fputcsv($handle, [__('app.grade') . ' ' . $grade]);
                foreach ($gradePayments as $p) {
                    $ct = str_starts_with($p->time_type ?? '', 'sat-sun')
                        ? __('app.weekend')
                        : __('app.weekday');
                    fputcsv($handle, [
                        $p->receipt_number,
                        $p->student?->student_id ?? '',
                        $p->student?->full_name ?? '',
                        $grade,
                        $ct,
                        $p->amount_due,
                        $p->admin_fee ?? 0,
                        $p->discount ?? 0,
                        $p->amount_paid,
                        $p->balance ?? 0,
                        $p->payment_date?->format('Y-m-d') ?? '',
                        $p->due_date?->format('Y-m-d') ?? '',
                        $p->next_payment_date?->format('Y-m-d') ?? '',
                        $p->status,
                        $p->payment_method ?? '',
                        $p->time_type ?? '',
                        $p->notes ?? '',
                    ]);
                }
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── CSV Export — Deadline Alerts ──────────────────────────────────────────

    public function exportAlertsCsv(Request $request)
    {
        $now      = Carbon::now();
        $students = $this->activeStudentsWithLastPayment()->get();
        $filter    = $request->get('filter', 'all');
        $classType = $request->get('class_type', '');

        // Apply class type filter before building alert data
        if ($classType === 'weekday') {
            $students = $students->filter(fn ($s) => str_starts_with($s->time_type ?? '', 'mon-fri'));
        } elseif ($classType === 'weekend') {
            $students = $students->filter(fn ($s) => str_starts_with($s->time_type ?? '', 'sat-sun'));
        }

        // Filter out students with 100% discount
        $students = $students->filter(fn ($s) => ($s->discount ?? 0) < 100);

        $rows = $students->map(fn ($s) => $this->buildStudentAlertData($s, $now))
            ->when($filter !== 'all', fn ($c) => $c->filter(fn ($d) => $d['alertLevel'] === $filter))
            ->sortBy(fn ($d) => $d['daysUntilNextPayment'] ?? 0)
            ->values();

        $classLabel = match($classType) {
            'weekday' => '_weekday',
            'weekend' => '_weekend',
            default   => '',
        };

        $filename = 'deadline_alerts' . $classLabel . '_' . now()->format('Y-m-d') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($rows, $classType) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            $classLabel = match($classType) {
                'weekday' => ' (' . __('app.weekday_class') . ')',
                'weekend' => ' (' . __('app.weekend_class') . ')',
                default   => '',
            };

            fputcsv($handle, [__('app.deadline_alerts') . $classLabel . ' — ' . now()->format('d/m/Y')]);
            fputcsv($handle, []);

            fputcsv($handle, [
                __('app.student_id'), __('app.first_name') . ' ' . __('app.last_name'),
                __('app.grade'), __('app.weekday') . '/' . __('app.weekend'),
                __('app.subject'), __('app.next_payment'), 'Days Until Due',
                __('app.status'), 'Last Payment Date', __('app.monthly_fee'),
            ]);

            $byGrade = $rows->groupBy(function ($d) {
                $s = $d['studentModel'] ?? $d['student'];
                return is_array($s) ? ($s['year_level'] ?? '?') : ($s->year_level ?? '?');
            })->toArray();
            ksort($byGrade);
            foreach ($byGrade as $grade => $gradeRows) {
                fputcsv($handle, []);
                fputcsv($handle, [__('app.grade') . ' ' . $grade]);
                foreach ($gradeRows as $d) {
                    $s = $d['studentModel'] ?? $d['student'];
                    $isArr = is_array($s);
                    $timeType = $isArr ? ($s['time_type'] ?? '') : ($s->time_type ?? '');
                    $ct = str_starts_with($timeType, 'sat-sun')
                        ? __('app.weekend')
                        : __('app.weekday');
                    $studentId  = $isArr ? ($s['student_id']  ?? '') : ($s->student_id  ?? '');
                    $fullName   = $isArr ? ($s['full_name']   ?? '') : ($s->full_name   ?? '');
                    $yearLevel  = $isArr ? ($s['year_level']  ?? '') : ($s->year_level  ?? '');
                    $subject    = $isArr ? ($s['subject']     ?? '') : ($s->subject     ?? '');
                    $monthlyFee = $isArr ? ($s['monthly_fee'] ?? '') : ($s->monthly_fee ?? '');

                    $nextDate = $d['nextPaymentDateFormatted'] ?? ($d['nextPaymentDate']?->format('Y-m-d') ?? 'N/A');
                    
                    $lastPayment = $d['lastPaymentModel'] ?? $d['lastPayment'];
                    if (is_array($lastPayment)) {
                        $lastDate = $lastPayment['payment_date_formatted'] ?? 'N/A';
                    } else {
                        $lastDate = $lastPayment?->payment_date?->format('Y-m-d') ?? 'N/A';
                    }

                    fputcsv($handle, [
                        $studentId,
                        $fullName,
                        $yearLevel,
                        $ct,
                        $subject,
                        $nextDate,
                        $d['daysUntilNextPayment'] ?? 'N/A',
                        __('app.' . $d['alertLevel']) ?? $d['alertLevel'],
                        $lastDate,
                        $monthlyFee,
                    ]);
                }
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(Payment $payment)
    {
        if ($payment->photo) {
            Storage::disk('public')->delete($payment->photo);
        }
        $payment->delete();
        return redirect()->back()
            ->with('success', 'Payment deleted successfully!');
    }

    // ── PDF Receipt ───────────────────────────────────────────────────────────

    private function buildReceiptMpdf(Payment $payment): \Mpdf\Mpdf
    {
        $fontDir  = storage_path('fonts');
        $tmpDir   = storage_path('framework/cache/mpdf');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Raise pcre limit — base64 font in HTML can hit the 1MB default
        @ini_set('pcre.backtrack_limit', '5000000');

        // Get logo as data URI (base64 encoded)
        $logoDataUri = '';
        // Try multiple logo files
        $logoFiles = [
            public_path('CK.png'),
            public_path('logo.png'),
            storage_path('fonts/logo.png'),
        ];
        foreach ($logoFiles as $path) {
            if (file_exists($path)) {
                $logoContent = file_get_contents($path);
                $logoDataUri = 'data:image/png;base64,' . base64_encode($logoContent);
                break;
            }
        }

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A5',
            'margin_top'    => 10,
            'margin_bottom' => 5,
            'margin_left'   => 5,
            'margin_right'  => 5,
            'tempDir'       => $tmpDir,
            'fontDir'       => array_merge(
                (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'],
                [$fontDir]
            ),
            'fontdata' => array_merge(
                $defaultFontConfig['fontdata'],
                [
                    'kantumruypro' => [
                        'R'  => 'KantumruyPro.ttf',
                        'B'  => 'KantumruyPro.ttf',
                        'I'  => 'KantumruyPro.ttf',
                        'BI' => 'KantumruyPro.ttf',
                    ],
                ]
            ),
            'default_font'    => 'kantumruypro',
            'allowCJKOrphans' => false,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        // ── Native mPDF watermark ──────────────────────────────
        if ($payment->status === 'paid') {
            $mpdf->SetWatermarkText('PAID');
            $mpdf->watermark_font      = 'dejavusans';
            $mpdf->watermarkTextAlpha  = 0.06;
            $mpdf->showWatermarkText   = true;
        }

        $html = view('receipts.payment', compact('payment', 'logoDataUri'))->render();
        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    public function receipt(Payment $payment)
    {
        $payment->load(['student', 'paymentType', 'creator']);
        $mpdf = $this->buildReceiptMpdf($payment);
        $filename = 'receipt-' . $payment->receipt_number . '.pdf';
        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function receiptDownload(Payment $payment)
    {
        $payment->load(['student', 'paymentType', 'creator']);
        $mpdf = $this->buildReceiptMpdf($payment);
        $filename = 'receipt-' . $payment->receipt_number . '.pdf';
        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── Alert helpers ─────────────────────────────────────────────────────────

    private function buildStudentAlertData(Student $student, Carbon $now): array
    {
        $lastPayment = $student->payments->first();

        $next = null;
        $nextPaymentDateFormatted = null;
        $daysUntilNextPayment = null;
        $alertLevel = 'upcoming';
        $overdueMonth = null;
        $monthKey = null;
        $monthLabel = null;

        if ($lastPayment && $lastPayment->payment_date) {
            // Use the stored next_payment_date if available (most accurate)
            // Otherwise recalculate using the anchored logic
            if ($lastPayment->next_payment_date) {
                $next = Carbon::parse($lastPayment->next_payment_date);
            } else {
                $paymentDay = (int) ($student->monthly_payment_day ?? $lastPayment->payment_date->day);
                $next = Student::nextPaymentDateFrom(
                    Carbon::parse($lastPayment->payment_date),
                    $paymentDay
                );
            }

            $days = (int) $now->diffInDays($next, false);

            $nextPaymentDateFormatted = $next->format('M d, Y');
            $daysUntilNextPayment = $days;
            $alertLevel           = match (true) {
                $days < 0  => 'overdue',
                $days <= 7 => 'closely',
                default   => 'upcoming',
            };
            $overdueMonth         = $days < 0 ? $next->format('F Y') : null;
            $monthKey             = $next->format('Y-m');
            $monthLabel           = $next->format('F Y');
        }

        return [
            'student'              => [
                'id'          => $student->id,
                'full_name'   => $student->full_name,
                'student_id'  => $student->student_id,
                'year_level'  => $student->year_level,
                'time_type'   => $student->time_type,
                'subject'     => $student->subject,
                'monthly_fee' => (float) ($student->monthly_fee ?? 0),
                'gender'      => $student->gender,
            ],
            'studentModel'             => $student,
            'lastPayment'              => $lastPayment ? [
                'due_date_formatted'     => $lastPayment->due_date?->format('M d, Y'),
                'payment_date_formatted' => $lastPayment->payment_date?->format('M d, Y'),
            ] : null,
            'lastPaymentModel'         => $lastPayment,
            'nextPaymentDate'          => $next,
            'nextPaymentDateFormatted' => $nextPaymentDateFormatted,
            'daysUntilNextPayment'     => $daysUntilNextPayment,
            'alertLevel'               => $alertLevel,
            'overdueMonth'             => $overdueMonth,
            'monthKey'                 => $monthKey,
            'monthLabel'               => $monthLabel,
        ];
    }

    // ── Deadline Alerts ───────────────────────────────────────────────────────

    public function deadlineAlerts(Request $request)
    {
        $now               = Carbon::now();
        $currentMonthKey   = $now->format('Y-m');
        $classType         = $request->get('class_type', '');
        $students          = $this->activeStudentsWithLastPayment()->get();

        // Apply class type filter
        if ($classType === 'weekday') {
            $students = $students->filter(fn ($s) => str_starts_with($s->time_type ?? '', 'mon-fri'));
        } elseif ($classType === 'weekend') {
            $students = $students->filter(fn ($s) => str_starts_with($s->time_type ?? '', 'sat-sun'));
        }

        // Filter out students with 100% discount
        $students = $students->filter(fn ($s) => ($s->discount ?? 0) < 100);

        $overdue         = collect();
        $allByMonth      = collect();
        $closely         = collect();
        $upcoming        = collect();
        $all             = collect();

        foreach ($students as $student) {
            $data = $this->buildStudentAlertData($student, $now);
            $all->push($data);
            match ($data['alertLevel']) {
                'overdue' => $overdue->push($data),
                'closely' => $closely->push($data),
                default   => $upcoming->push($data),
            };
        }

        // Group ALL students by month, sorted descending, and sort students within month
        $allByMonth = $all->groupBy('monthKey')->map(function ($items, $monthKey) {
            $sortedStudents = $items->sortBy('daysUntilNextPayment')->values();
            return [
                'monthKey' => $monthKey,
                'monthLabel' => $items->first()['monthLabel'],
                'students' => $sortedStudents,
            ];
        })->sortByDesc('monthKey')->values();

        // ── Filter / search for all students ────────────
        $filterLevel = $request->get('filter', 'all'); // all | overdue | closely | upcoming
        $search      = trim($request->get('search', ''));
        $filterGrade = $request->get('grade', '');

        $filtered = $all->filter(function ($d) use ($filterLevel, $search, $filterGrade) {
            if ($filterLevel !== 'all' && $d['alertLevel'] !== $filterLevel) return false;
            
            $s = $d['studentModel'] ?? $d['student'];
            $isArr = is_array($s);
            $yearLevel = $isArr ? ($s['year_level'] ?? '') : ($s->year_level ?? '');
            if ($filterGrade !== '' && (string)$yearLevel !== $filterGrade) return false;
            
            if ($search !== '') {
                $fullName  = $isArr ? ($s['full_name']  ?? '') : ($s->full_name  ?? '');
                $studentId = $isArr ? ($s['student_id'] ?? '') : ($s->student_id ?? '');
                $subject   = $isArr ? ($s['subject']    ?? '') : ($s->subject    ?? '');
                
                $hay = strtolower($fullName . ' ' . $studentId . ' ' . $subject);
                if (strpos($hay, strtolower($search)) === false) return false;
            }
            return true;
        })->values();

        // Re-group filtered students by month, sorted descending, and sort students within month
        $filteredByMonth = $filtered->groupBy('monthKey')->map(function ($items, $monthKey) {
            $sortedStudents = $items->sortBy('daysUntilNextPayment')->values();
            return [
                'monthKey' => $monthKey,
                'monthLabel' => $items->first()['monthLabel'],
                'students' => $sortedStudents,
            ];
        })->sortByDesc('monthKey')->values();

        $availableGrades = $all->pluck('student.year_level')->unique()->filter()->sort()->values();

        return view('payments.alerts', [
            'overdue'         => $overdue,
            'allByMonth'      => $filteredByMonth,
            'currentMonthKey' => $currentMonthKey,
            'closely'         => $closely,
            'upcoming'        => $upcoming,
            'allStudentData'  => $filtered,
            'totalCount'      => $all->count(),
            'filterLevel'     => $filterLevel,
            'search'          => $search,
            'filterGrade'     => $filterGrade,
            'availableGrades' => $availableGrades,
            'classType'       => $classType,
        ]);
    }

    public function alertsOverdue()
    {
        $now    = Carbon::now();
        $grades = $this->activeStudentsWithLastPayment()->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100 && $this->buildStudentAlertData($s, $now)['alertLevel'] === 'overdue')
            ->pluck('year_level')->unique()->sort()->values();

        return view('payments.alerts-grades', ['grades' => $grades, 'title' => 'Overdue Payments', 'type' => 'overdue']);
    }

    public function alertsOverdueGrade(string|int $grade)
    {
        $now  = Carbon::now();
        $data = $this->activeStudentsWithLastPayment()->where('year_level', $grade)->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100)
            ->map(fn ($s) => $this->buildStudentAlertData($s, $now))
            ->filter(fn ($d) => $d['alertLevel'] === 'overdue')->values();

        return view('payments.alerts-grades', ['studentData' => $data, 'title' => "Overdue — Grade {$grade}", 'type' => 'overdue']);
    }

    public function alertsClosely()
    {
        $now    = Carbon::now();
        $grades = $this->activeStudentsWithLastPayment()->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100 && $this->buildStudentAlertData($s, $now)['alertLevel'] === 'closely')
            ->pluck('year_level')->unique()->sort()->values();

        return view('payments.alerts-grades', ['grades' => $grades, 'title' => 'Closely Date Payments', 'type' => 'closely']);
    }

    public function alertsCloselyGrade(string|int $grade)
    {
        $now  = Carbon::now();
        $data = $this->activeStudentsWithLastPayment()->where('year_level', $grade)->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100)
            ->map(fn ($s) => $this->buildStudentAlertData($s, $now))
            ->filter(fn ($d) => $d['alertLevel'] === 'closely')->values();

        return view('payments.alerts-grades', ['studentData' => $data, 'title' => "Closely Date — Grade {$grade}", 'type' => 'closely']);
    }

    public function alertsUpcoming()
    {
        $now    = Carbon::now();
        $grades = $this->activeStudentsWithLastPayment()->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100 && $this->buildStudentAlertData($s, $now)['alertLevel'] === 'upcoming')
            ->pluck('year_level')->unique()->sort()->values();

        return view('payments.alerts-grades', ['grades' => $grades, 'title' => 'Upcoming Payments', 'type' => 'upcoming']);
    }

    public function alertsUpcomingGrade(string|int $grade)
    {
        $now  = Carbon::now();
        $data = $this->activeStudentsWithLastPayment()->where('year_level', $grade)->get()
            ->filter(fn ($s) => ($s->discount ?? 0) < 100)
            ->map(fn ($s) => $this->buildStudentAlertData($s, $now))
            ->filter(fn ($d) => $d['alertLevel'] === 'upcoming')->values();

        return view('payments.alerts-grades', ['studentData' => $data, 'title' => "Upcoming — Grade {$grade}", 'type' => 'upcoming']);
    }
}
