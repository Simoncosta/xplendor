<?php

namespace App\Mail;

use App\Models\Car;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CarSoldNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $brand;
    public string $model;
    public string $version;
    public ?string $licensePlate;

    public function __construct(Car $car)
    {
        $this->companyName = $car->company?->trade_name
            ?? $car->company?->fiscal_name
            ?? 'Empresa desconhecida';
        $this->brand = $car->brand?->name ?? '—';
        $this->model = $car->model?->name ?? '—';
        $this->version = $car->version ?: '—';
        $this->licensePlate = $car->license_plate ?: null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mais um carro foi vendido',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.cars.sold',
            with: [
                'companyName' => $this->companyName,
                'brand' => $this->brand,
                'model' => $this->model,
                'version' => $this->version,
                'licensePlate' => $this->licensePlate,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
